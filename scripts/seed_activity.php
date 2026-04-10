<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

use App\Core\Database;
use App\Core\Request;
use App\Models\AdoptionSeminar;
use App\Models\Animal;
use App\Services\AdoptionService;
use App\Services\BillingService;
use App\Services\MedicalService;
use Faker\Factory;
use Faker\Generator;

$options = parseOptions(array_slice($argv, 1), [
    'medical' => 45,
    'adoptions' => 18,
    'seminars' => 3,
    'seed' => random_int(100000, 999999),
]);

$faker = Factory::create();
$faker->seed($options['seed']);

$operator = Database::fetch(
    "SELECT u.id, u.first_name, u.last_name, u.phone, u.address_line1, u.city, u.province, u.zip_code, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_deleted = 0
       AND u.is_active = 1
       AND r.name IN ('super_admin', 'shelter_head', 'shelter_staff')
     ORDER BY FIELD(r.name, 'super_admin', 'shelter_head', 'shelter_staff'), u.id ASC
     LIMIT 1"
);
$veterinarian = Database::fetch(
    "SELECT u.id, u.first_name, u.last_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_deleted = 0
       AND u.is_active = 1
       AND r.name = 'veterinarian'
     ORDER BY u.id ASC
     LIMIT 1"
);
$facilitators = Database::fetchAll(
    "SELECT u.id, u.first_name, u.last_name, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_deleted = 0
       AND u.is_active = 1
       AND r.name IN ('super_admin', 'shelter_head', 'shelter_staff', 'veterinarian')
     ORDER BY FIELD(r.name, 'super_admin', 'shelter_head', 'shelter_staff', 'veterinarian'), u.id ASC"
);
$adopters = Database::fetchAll(
    "SELECT u.id, u.username, u.first_name, u.last_name, u.phone, u.address_line1, u.address_line2, u.city, u.province, u.zip_code
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_deleted = 0
       AND u.is_active = 1
       AND r.name = 'adopter'
     ORDER BY u.id ASC"
);
$adoptionAnimals = Database::fetchAll(
    "SELECT a.id, a.animal_id, a.name, a.species, a.gender, a.size, a.weight_kg, COALESCE(b.name, a.breed_other, 'Mixed') AS breed_name
     FROM animals a
     LEFT JOIN breeds b ON b.id = a.breed_id
     WHERE a.is_deleted = 0
       AND a.status = 'Available'
       AND a.species IN ('Dog', 'Cat')
       AND NOT EXISTS (
            SELECT 1
            FROM adoption_applications aa
            WHERE aa.animal_id = a.id
              AND aa.is_deleted = 0
              AND aa.status NOT IN ('completed', 'rejected', 'withdrawn')
       )
     ORDER BY a.created_at DESC, a.id DESC"
);
$treatmentInventory = Database::fetch(
    "SELECT id, sku, name, quantity_on_hand, unit_of_measure
     FROM inventory_items
     WHERE is_deleted = 0
       AND is_active = 1
       AND quantity_on_hand > 0
     ORDER BY quantity_on_hand DESC, id ASC
     LIMIT 1"
);

if ($operator === false) {
    fwrite(STDERR, "No active staff user is available to seed activity.\n");
    exit(1);
}

if ($veterinarian === false) {
    fwrite(STDERR, "No active veterinarian user is available to seed medical activity.\n");
    exit(1);
}

if ($adopters === []) {
    fwrite(STDERR, "No active adopter users are available to seed adoption activity.\n");
    exit(1);
}

if ($adoptionAnimals === []) {
    fwrite(STDERR, "No eligible available animals are currently open for seeded adoption activity.\n");
    exit(1);
}

$requestedAdoptions = min($options['adoptions'], count($adoptionAnimals));
$requestedSeminars = max(1, min($options['seminars'], $requestedAdoptions));
$operatorId = (int) $operator['id'];
$adoptionService = new AdoptionService();
$medicalService = new MedicalService();
$billingService = new BillingService();
$seminars = new AdoptionSeminar();
$animals = new Animal();
$placeholderDocument = ensureValidIdPlaceholder();
$created = [
    'seminars' => 0,
    'adoption_applications' => 0,
    'interviews' => 0,
    'seminar_attendees' => 0,
    'invoices' => 0,
    'payments' => 0,
    'completions' => 0,
    'medical_records' => 0,
];
$adoptionStatusCounts = [];
$medicalTypeCounts = [];
$failures = [];

$before = [
    'medical_records' => aggregate('SELECT COUNT(*) AS aggregate FROM medical_records WHERE is_deleted = 0'),
    'adoption_applications' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_applications WHERE is_deleted = 0'),
    'adoption_seminars' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_seminars'),
    'seminar_attendees' => aggregate('SELECT COUNT(*) AS aggregate FROM seminar_attendees'),
    'adoption_completions' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_completions'),
    'invoices' => aggregate('SELECT COUNT(*) AS aggregate FROM invoices WHERE is_deleted = 0'),
    'payments' => aggregate('SELECT COUNT(*) AS aggregate FROM payments'),
];

$seminarIds = [];
for ($index = 0; $index < $requestedSeminars; $index++) {
    try {
        $seminarIds[] = $seminars->create(buildSeminarPayload(
            $faker,
            $operatorId,
            $facilitators[$index % count($facilitators)],
            $index,
            $requestedAdoptions
        ));
        $created['seminars']++;
    } catch (Throwable $exception) {
        $failures[] = 'Seminar ' . ($index + 1) . ': ' . $exception->getMessage();
    }
}

if ($seminarIds === [] && $requestedAdoptions > 0) {
    fwrite(STDERR, "No seminars were created, so adoption activity cannot continue safely.\n");
    exit(1);
}

$adoptionPlans = buildAdoptionPlans($requestedAdoptions);
$selectedAnimals = array_slice($adoptionAnimals, 0, $requestedAdoptions);

foreach ($adoptionPlans as $index => $plan) {
    if (!isset($selectedAnimals[$index])) {
        break;
    }

    $animal = $selectedAnimals[$index];
    $adopter = $adopters[$index % count($adopters)];

    try {
        $application = $adoptionService->submitPortalApplication(
            (int) $adopter['id'],
            buildPortalApplicationPayload($faker, $animal),
            buildPortalFile($placeholderDocument),
            requestFor('POST', '/adopt/apply', ['animal_id' => $animal['id']])
        );
        $applicationId = (int) $application['id'];
        $created['adoption_applications']++;

        if ($plan['status'] !== 'pending_review') {
            $animals->updateStatus(
                (int) $animal['id'],
                'In Adoption Process',
                'Seeded adoption activity is currently in progress.',
                null,
                $operatorId
            );
        }

        if (requiresInterview($plan['status'])) {
            $scheduledInterview = buildScheduledInterviewPayload($faker, $facilitators[$index % count($facilitators)]);
            $adoptionService->scheduleInterview(
                $applicationId,
                $scheduledInterview,
                $operatorId,
                requestFor('POST', '/api/adoptions/' . $applicationId . '/interviews', $scheduledInterview)
            );
            $created['interviews']++;
        }

        if (requiresCompletedInterview($plan['status'])) {
            $latestApplication = $adoptionService->get($applicationId);
            $latestInterview = $latestApplication['interviews'][0] ?? null;
            if (!is_array($latestInterview)) {
                throw new RuntimeException('Seeded interview was not found after scheduling.');
            }

            $completedInterviewPayload = buildCompletedInterviewPayload($faker, $latestInterview);
            $adoptionService->updateInterview(
                (int) $latestInterview['id'],
                $completedInterviewPayload,
                $operatorId,
                requestFor('PUT', '/api/adoption-interviews/' . $latestInterview['id'], $completedInterviewPayload)
            );
        }

        if (requiresSeminarRegistration($plan['status'])) {
            $seminarId = $seminarIds[$index % count($seminarIds)];
            $adoptionService->registerAttendee(
                $seminarId,
                $applicationId,
                $operatorId,
                requestFor('POST', '/api/adoption-seminars/' . $seminarId . '/attendees', ['application_id' => $applicationId])
            );
            $created['seminar_attendees']++;
        }

        if ($plan['invoice'] !== 'none') {
            $invoice = $billingService->createInvoice(
                buildInvoicePayload($adopter, $animal, $applicationId),
                $operatorId,
                requestFor('POST', '/api/billing/invoices', ['application_id' => $applicationId])
            );
            $created['invoices']++;

            if ($plan['invoice'] === 'paid') {
                $billingService->recordPayment(
                    (int) $invoice['id'],
                    buildPaymentPayload((float) $invoice['total_amount']),
                    $operatorId,
                    requestFor('POST', '/api/billing/invoices/' . $invoice['id'] . '/payments', ['invoice_id' => $invoice['id']])
                );
                $created['payments']++;
            }
        }

        if (requiresAttendedSeminar($plan['status'])) {
            $seminarId = $seminarIds[$index % count($seminarIds)];
            $adoptionService->updateAttendance(
                $seminarId,
                $applicationId,
                'attended',
                $operatorId,
                requestFor('PUT', '/api/adoption-seminars/' . $seminarId . '/attendees/' . $applicationId, [
                    'attendance_status' => 'attended',
                ])
            );
        }

        if ($plan['status'] === 'completed') {
            $completionPayload = [
                'completion_date' => date('Y-m-d H:i:s', strtotime('-' . $faker->numberBetween(1, 15) . ' days')),
                'payment_confirmed' => $plan['invoice'] === 'unpaid',
                'contract_signed' => true,
                'medical_records_provided' => $faker->boolean(80),
                'spay_neuter_agreement' => $faker->boolean(75),
                'notes' => 'Seeded completed adoption for local development activity.',
            ];
            $adoptionService->complete(
                $applicationId,
                $completionPayload,
                $operatorId,
                requestFor('POST', '/api/adoptions/' . $applicationId . '/complete', $completionPayload)
            );
            $created['completions']++;
        }

        $finalApplication = $adoptionService->get($applicationId);
        $finalStatus = (string) ($finalApplication['status'] ?? 'unknown');
        $adoptionStatusCounts[$finalStatus] = ($adoptionStatusCounts[$finalStatus] ?? 0) + 1;
    } catch (Throwable $exception) {
        $failures[] = 'Adoption for animal ' . ($animal['animal_id'] ?? $animal['id']) . ': ' . $exception->getMessage();
    }
}

$medicalCandidates = Database::fetchAll(
    "SELECT a.id, a.animal_id, a.name, a.species, a.gender, a.size, a.weight_kg
     FROM animals a
     WHERE a.is_deleted = 0
       AND a.status NOT IN ('Adopted', 'Deceased', 'Transferred')
       AND NOT EXISTS (
            SELECT 1
            FROM adoption_applications aa
            WHERE aa.animal_id = a.id
              AND aa.is_deleted = 0
              AND aa.status NOT IN ('completed', 'rejected', 'withdrawn')
       )
     ORDER BY a.created_at DESC, a.id DESC"
);
$treatmentCapacity = $treatmentInventory !== false ? (int) $treatmentInventory['quantity_on_hand'] : 0;
$medicalPlan = buildMedicalTypePlan($options['medical'], $treatmentCapacity);

foreach ($medicalPlan as $index => $procedureType) {
    if ($medicalCandidates === []) {
        break;
    }

    $animal = $medicalCandidates[$index % count($medicalCandidates)];

    try {
        $payload = buildMedicalPayload(
            $faker,
            $procedureType,
            $animal,
            (int) $veterinarian['id'],
            $treatmentInventory !== false ? (int) $treatmentInventory['id'] : null,
            $treatmentInventory !== false ? (string) $treatmentInventory['name'] : null
        );

        $medicalService->create(
            $procedureType,
            $payload,
            $operatorId,
            requestFor('POST', '/api/medical/' . $procedureType, $payload)
        );
        $created['medical_records']++;
        $medicalTypeCounts[$procedureType] = ($medicalTypeCounts[$procedureType] ?? 0) + 1;

        if ($procedureType === 'treatment' && $treatmentCapacity > 0) {
            $treatmentCapacity--;
        }
    } catch (Throwable $exception) {
        $failures[] = 'Medical ' . $procedureType . ' for animal ' . ($animal['animal_id'] ?? $animal['id']) . ': ' . $exception->getMessage();
    }
}

$after = [
    'medical_records' => aggregate('SELECT COUNT(*) AS aggregate FROM medical_records WHERE is_deleted = 0'),
    'adoption_applications' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_applications WHERE is_deleted = 0'),
    'adoption_seminars' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_seminars'),
    'seminar_attendees' => aggregate('SELECT COUNT(*) AS aggregate FROM seminar_attendees'),
    'adoption_completions' => aggregate('SELECT COUNT(*) AS aggregate FROM adoption_completions'),
    'invoices' => aggregate('SELECT COUNT(*) AS aggregate FROM invoices WHERE is_deleted = 0'),
    'payments' => aggregate('SELECT COUNT(*) AS aggregate FROM payments'),
];

$currentInventoryLevel = $treatmentInventory !== false
    ? (int) (Database::fetch(
        'SELECT quantity_on_hand FROM inventory_items WHERE id = :id LIMIT 1',
        ['id' => (int) $treatmentInventory['id']]
    )['quantity_on_hand'] ?? 0)
    : null;

$pipelineRows = Database::fetchAll(
    "SELECT status, COUNT(*) AS aggregate
     FROM adoption_applications
     WHERE is_deleted = 0
     GROUP BY status
     ORDER BY status ASC"
);
$pipelineTotals = [];
foreach ($pipelineRows as $row) {
    $pipelineTotals[(string) $row['status']] = (int) $row['aggregate'];
}

echo json_encode([
    'seed' => $options['seed'],
    'requested' => [
        'medical_records' => $options['medical'],
        'adoption_applications' => $options['adoptions'],
        'seminars' => $options['seminars'],
    ],
    'created' => $created,
    'before' => $before,
    'after' => $after,
    'adoption_statuses_created' => $adoptionStatusCounts,
    'adoption_pipeline_totals' => $pipelineTotals,
    'medical_types_created' => $medicalTypeCounts,
    'treatment_inventory' => $treatmentInventory !== false ? [
        'item_id' => (int) $treatmentInventory['id'],
        'name' => (string) $treatmentInventory['name'],
        'quantity_before' => (int) $treatmentInventory['quantity_on_hand'],
        'quantity_after' => $currentInventoryLevel,
    ] : null,
    'failures' => $failures,
], JSON_PRETTY_PRINT) . PHP_EOL;

function parseOptions(array $arguments, array $defaults): array
{
    $options = $defaults;

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--seed=')) {
            $options['seed'] = max(1, (int) substr($argument, 7));
            continue;
        }

        if (str_starts_with($argument, '--medical=')) {
            $options['medical'] = max(0, (int) substr($argument, 10));
            continue;
        }

        if (str_starts_with($argument, '--adoptions=')) {
            $options['adoptions'] = max(0, (int) substr($argument, 12));
            continue;
        }

        if (str_starts_with($argument, '--seminars=')) {
            $options['seminars'] = max(1, (int) substr($argument, 11));
        }
    }

    return $options;
}

function aggregate(string $sql, array $bindings = []): int
{
    return (int) (Database::fetch($sql, $bindings)['aggregate'] ?? 0);
}

function ensureValidIdPlaceholder(): string
{
    $directory = dirname(__DIR__) . '/storage/runtime';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to prepare the runtime directory for seeded adoption documents.');
    }

    $path = $directory . '/seed-valid-id.txt';
    if (!file_exists($path)) {
        file_put_contents(
            $path,
            "Seeded adopter identification placeholder.\nGenerated on " . date('Y-m-d H:i:s') . ".\n"
        );
    }

    return $path;
}

function buildPortalFile(string $placeholderPath): array
{
    return [
        'name' => 'seed-valid-id.txt',
        'type' => 'text/plain',
        'tmp_name' => $placeholderPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($placeholderPath) ?: 0,
    ];
}

function requestFor(string $method, string $path, array $body = []): Request
{
    return new Request(
        [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'seed-activity-script',
            'HTTP_ACCEPT' => 'application/json',
        ],
        [],
        $body,
        [],
        []
    );
}

function buildSeminarPayload(Generator $faker, int $operatorId, array $facilitator, int $index, int $adoptionTarget): array
{
    $start = $faker->dateTimeBetween('+4 days', '+24 days');
    $end = (clone $start)->modify('+' . $faker->numberBetween(2, 4) . ' hours');

    return [
        'title' => 'Adoption Orientation Batch ' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        'scheduled_date' => $start->format('Y-m-d H:i:s'),
        'end_time' => $end->format('Y-m-d H:i:s'),
        'location' => $faker->randomElement([
            'Shelter Training Room',
            'Municipal Hall Conference Room',
            'Community Outreach Center',
        ]),
        'capacity' => max(8, (int) ceil($adoptionTarget / max(1, $index + 1)) + 3),
        'facilitator_id' => (int) $facilitator['id'],
        'description' => 'Seeded seminar schedule for local adoption workflow testing.',
        'status' => 'scheduled',
        'created_by' => $operatorId,
    ];
}

function buildAdoptionPlans(int $count): array
{
    $plans = [
        ['status' => 'pending_review', 'invoice' => 'none'],
        ['status' => 'pending_review', 'invoice' => 'none'],
        ['status' => 'pending_review', 'invoice' => 'none'],
        ['status' => 'pending_review', 'invoice' => 'none'],
        ['status' => 'interview_scheduled', 'invoice' => 'none'],
        ['status' => 'interview_scheduled', 'invoice' => 'none'],
        ['status' => 'interview_scheduled', 'invoice' => 'none'],
        ['status' => 'interview_completed', 'invoice' => 'none'],
        ['status' => 'interview_completed', 'invoice' => 'none'],
        ['status' => 'interview_completed', 'invoice' => 'none'],
        ['status' => 'seminar_scheduled', 'invoice' => 'none'],
        ['status' => 'seminar_scheduled', 'invoice' => 'none'],
        ['status' => 'seminar_completed', 'invoice' => 'none'],
        ['status' => 'seminar_completed', 'invoice' => 'none'],
        ['status' => 'pending_payment', 'invoice' => 'unpaid'],
        ['status' => 'pending_payment', 'invoice' => 'unpaid'],
        ['status' => 'completed', 'invoice' => 'paid'],
        ['status' => 'completed', 'invoice' => 'none'],
    ];

    if ($count <= count($plans)) {
        return array_slice($plans, 0, $count);
    }

    while (count($plans) < $count) {
        $plans[] = ['status' => 'pending_review', 'invoice' => 'none'];
    }

    return $plans;
}

function buildPortalApplicationPayload(Generator $faker, array $animal): array
{
    $housingType = $faker->randomElement(['House', 'Apartment', 'Condo']);
    $hasYard = $housingType === 'House' ? $faker->boolean(75) : false;

    return [
        'animal_id' => (int) $animal['id'],
        'preferred_species' => (string) $animal['species'],
        'preferred_breed' => (string) ($animal['breed_name'] ?? ''),
        'preferred_age_min' => $faker->numberBetween(0, 2),
        'preferred_age_max' => $faker->numberBetween(3, 10),
        'preferred_size' => (string) ($animal['size'] ?? 'Medium'),
        'preferred_gender' => (string) ($animal['gender'] ?? ''),
        'housing_type' => $housingType,
        'housing_ownership' => $faker->randomElement(['Owned', 'Rented']),
        'has_yard' => $hasYard,
        'yard_size' => $hasYard ? $faker->randomElement(['Small', 'Medium', 'Large']) : '',
        'num_adults' => $faker->numberBetween(1, 4),
        'num_children' => $faker->numberBetween(0, 3),
        'children_ages' => $faker->boolean(35) ? implode(', ', [
            (string) $faker->numberBetween(4, 9),
            (string) $faker->numberBetween(10, 16),
        ]) : '',
        'existing_pets_description' => $faker->boolean(55)
            ? $faker->randomElement([
                'One vaccinated dog already lives in the home.',
                'Two indoor cats with separate feeding spaces.',
                'No current pets, but past experience with rescued animals.',
            ])
            : '',
        'previous_pet_experience' => $faker->randomElement([
            'Previously cared for rescued dogs for several years.',
            'Has experience with routine feeding, grooming, and veterinary visits.',
            'Family is familiar with basic obedience and house training.',
        ]),
        'vet_reference_name' => $faker->name(),
        'vet_reference_clinic' => $faker->randomElement([
            'Catarman Veterinary Clinic',
            'Northern Samar Animal Care',
            'Poblacion Pet Wellness Center',
        ]),
        'vet_reference_contact' => phMobile($faker),
        'agrees_to_policies' => true,
        'agrees_to_home_visit' => true,
        'agrees_to_return_policy' => true,
    ];
}

function requiresInterview(string $status): bool
{
    return in_array($status, [
        'interview_scheduled',
        'interview_completed',
        'seminar_scheduled',
        'seminar_completed',
        'pending_payment',
        'completed',
    ], true);
}

function requiresCompletedInterview(string $status): bool
{
    return in_array($status, [
        'interview_completed',
        'seminar_scheduled',
        'seminar_completed',
        'pending_payment',
        'completed',
    ], true);
}

function requiresSeminarRegistration(string $status): bool
{
    return in_array($status, [
        'seminar_scheduled',
        'seminar_completed',
        'pending_payment',
        'completed',
    ], true);
}

function requiresAttendedSeminar(string $status): bool
{
    return in_array($status, [
        'seminar_completed',
        'pending_payment',
        'completed',
    ], true);
}

function buildScheduledInterviewPayload(Generator $faker, array $facilitator): array
{
    $scheduledAt = $faker->dateTimeBetween('+2 days', '+18 days');
    $type = $faker->randomElement(['in_person', 'video_call']);

    return [
        'scheduled_date' => $scheduledAt->format('Y-m-d H:i:s'),
        'interview_type' => $type,
        'video_call_link' => $type === 'video_call' ? 'https://meet.google.com/' . strtolower($faker->bothify('seed-???-####')) : '',
        'location' => $type === 'in_person' ? $faker->randomElement([
            'Catarman Shelter Office',
            'Municipal Hall Interview Room',
        ]) : '',
        'conducted_by' => (int) $facilitator['id'],
    ];
}

function buildCompletedInterviewPayload(Generator $faker, array $interview): array
{
    $completedAt = $faker->dateTimeBetween('-16 days', '-1 day');

    return [
        'scheduled_date' => $completedAt->format('Y-m-d H:i:s'),
        'interview_type' => (string) $interview['interview_type'],
        'video_call_link' => (string) ($interview['video_call_link'] ?? ''),
        'location' => (string) ($interview['location'] ?? ''),
        'status' => 'completed',
        'screening_checklist' => json_encode([
            'housing_reviewed' => true,
            'family_interviewed' => true,
            'care_commitment_discussed' => true,
            'follow_up_ready' => $faker->boolean(85),
        ]),
        'home_assessment_notes' => $faker->randomElement([
            'Home environment appears stable and ready for transition.',
            'Applicant demonstrated a clear feeding and exercise plan.',
            'Family members are aligned on adoption responsibilities.',
        ]),
        'pet_care_knowledge_score' => $faker->numberBetween(7, 10),
        'overall_recommendation' => $faker->randomElement(['Approve', 'Conditional']),
        'interviewer_notes' => 'Seeded completed interview for local development testing.',
        'conducted_by' => (int) ($interview['conducted_by'] ?? 0),
    ];
}

function buildInvoicePayload(array $adopter, array $animal, int $applicationId): array
{
    return [
        'payor_type' => 'adopter',
        'payor_user_id' => (int) $adopter['id'],
        'payor_name' => trim((string) $adopter['first_name'] . ' ' . $adopter['last_name']),
        'payor_contact' => (string) ($adopter['phone'] ?? ''),
        'payor_address' => formatAddress($adopter),
        'animal_id' => (int) $animal['id'],
        'application_id' => $applicationId,
        'due_date' => date('Y-m-d', strtotime('+10 days')),
        'notes' => 'Seeded adoption invoice for local development.',
        'terms' => 'Payable before final adoption completion.',
        'line_items' => [
            [
                'fee_schedule_id' => null,
                'description' => 'Adoption processing fee',
                'quantity' => 1,
                'unit_price' => 750,
            ],
            [
                'fee_schedule_id' => null,
                'description' => 'Orientation and records packet',
                'quantity' => 1,
                'unit_price' => 250,
            ],
        ],
    ];
}

function buildPaymentPayload(float $amount): array
{
    return [
        'amount' => $amount,
        'payment_method' => 'Cash',
        'reference_number' => '',
        'payment_date' => date('Y-m-d'),
        'notes' => 'Seeded local development payment.',
    ];
}

function formatAddress(array $user): string
{
    $parts = array_filter([
        $user['address_line1'] ?? null,
        $user['address_line2'] ?? null,
        $user['city'] ?? null,
        $user['province'] ?? null,
        $user['zip_code'] ?? null,
    ], static fn ($value): bool => $value !== null && $value !== '');

    return implode(', ', $parts);
}

function buildMedicalTypePlan(int $count, int $treatmentCapacity): array
{
    if ($count <= 0) {
        return [];
    }

    $distribution = [
        'vaccination' => 0.30,
        'examination' => 0.24,
        'deworming' => 0.22,
        'surgery' => 0.12,
        'treatment' => 0.12,
    ];
    $counts = [];
    $allocated = 0;

    foreach ($distribution as $type => $weight) {
        $counts[$type] = (int) floor($count * $weight);
        $allocated += $counts[$type];
    }

    $order = ['vaccination', 'examination', 'deworming', 'surgery', 'treatment'];
    $cursor = 0;
    while ($allocated < $count) {
        $type = $order[$cursor % count($order)];
        $counts[$type]++;
        $allocated++;
        $cursor++;
    }

    if ($counts['treatment'] > $treatmentCapacity) {
        $overflow = $counts['treatment'] - $treatmentCapacity;
        $counts['treatment'] = $treatmentCapacity;
        $counts['examination'] += $overflow;
    }

    $plan = [];
    foreach ($counts as $type => $typeCount) {
        for ($index = 0; $index < $typeCount; $index++) {
            $plan[] = $type;
        }
    }

    shuffle($plan);

    return $plan;
}

function buildMedicalPayload(
    Generator $faker,
    string $type,
    array $animal,
    int $veterinarianId,
    ?int $inventoryItemId,
    ?string $inventoryItemName
): array {
    $recordedAt = $faker->dateTimeBetween('-180 days', '-1 day');
    $weight = max(0.5, (float) ($animal['weight_kg'] ?? $faker->randomFloat(2, 2.5, 28.0)));
    $payload = [
        'animal_id' => (int) $animal['id'],
        'record_date' => $recordedAt->format('Y-m-d H:i:s'),
        'general_notes' => 'Seeded ' . $type . ' record for local development testing.',
        'veterinarian_id' => $veterinarianId,
        'vs_weight_kg' => round($weight, 2),
        'vs_temperature_celsius' => round($faker->randomFloat(1, 37.1, 39.5), 1),
        'vs_heart_rate_bpm' => $faker->numberBetween(72, 142),
        'vs_respiratory_rate' => $faker->numberBetween(16, 38),
        'vs_body_condition_score' => $faker->numberBetween(3, 7),
    ];

    return match ($type) {
        'vaccination' => $payload + [
            'vaccine_name' => $faker->randomElement(['Anti-Rabies', '5-in-1', 'FVRCP', 'Leptospirosis']),
            'vaccine_brand' => $faker->randomElement(['Nobivac', 'Vanguard', 'Defensor', 'Zoetis']),
            'batch_lot_number' => strtoupper($faker->bothify('LOT-####??')),
            'dosage_ml' => round($faker->randomFloat(2, 0.5, 1.5), 2),
            'route' => $faker->randomElement(['Subcutaneous', 'Intramuscular', 'Oral']),
            'injection_site' => $faker->randomElement(['Left shoulder', 'Right shoulder', 'Scruff']),
            'dose_number' => $faker->numberBetween(1, 3),
            'next_due_date' => date('Y-m-d', strtotime($recordedAt->format('Y-m-d') . ' + ' . $faker->numberBetween(180, 365) . ' days')),
            'adverse_reactions' => '',
        ],
        'surgery' => $payload + [
            'surgery_type' => $faker->randomElement(['Spay', 'Neuter', 'Tumor Removal', 'Wound Repair']),
            'pre_op_weight_kg' => round($weight, 2),
            'anesthesia_type' => $faker->randomElement(['General', 'Local', 'Sedation']),
            'anesthesia_drug' => $faker->randomElement(['Isoflurane', 'Ketamine', 'Propofol']),
            'anesthesia_dosage' => $faker->randomElement(['0.8 ml', '1.0 ml', '1.2 ml']),
            'duration_minutes' => $faker->numberBetween(35, 120),
            'surgical_notes' => $faker->randomElement([
                'Procedure completed without immediate complications.',
                'Incision site was cleaned and closed with standard sutures.',
                'Patient tolerated the procedure and recovered under observation.',
            ]),
            'complications' => '',
            'post_op_instructions' => 'Keep the animal in a clean observation area and monitor wound healing daily.',
            'follow_up_date' => date('Y-m-d', strtotime($recordedAt->format('Y-m-d') . ' + ' . $faker->numberBetween(5, 14) . ' days')),
        ],
        'examination' => $payload + [
            'weight_kg' => round($weight, 2),
            'temperature_celsius' => round($faker->randomFloat(1, 37.2, 39.3), 1),
            'heart_rate_bpm' => $faker->numberBetween(70, 138),
            'respiratory_rate' => $faker->numberBetween(15, 35),
            'body_condition_score' => $faker->numberBetween(3, 7),
            'eyes_status' => 'Normal',
            'eyes_notes' => '',
            'ears_status' => 'Normal',
            'ears_notes' => '',
            'teeth_gums_status' => $faker->randomElement(['Normal', 'Abnormal']),
            'teeth_gums_notes' => $faker->boolean(35) ? 'Mild tartar buildup noted.' : '',
            'skin_coat_status' => $faker->randomElement(['Normal', 'Abnormal']),
            'skin_coat_notes' => $faker->boolean(40) ? 'Mild patchy hair loss under treatment.' : '',
            'musculoskeletal_status' => 'Normal',
            'musculoskeletal_notes' => '',
            'overall_assessment' => $faker->randomElement([
                'Animal is stable and responsive with manageable clinical findings.',
                'General health is acceptable with minor concerns requiring follow-up.',
                'Routine examination completed and monitoring was advised.',
            ]),
            'recommendations' => 'Continue observation and recheck if appetite or energy changes.',
        ],
        'treatment' => $payload + [
            'diagnosis' => $faker->randomElement(['Skin infection', 'Respiratory irritation', 'Wound management', 'Gastrointestinal upset']),
            'medication_name' => $inventoryItemName ?? $faker->randomElement(['Amoxicillin', 'Topical Ointment', 'Doxycycline']),
            'dosage' => $faker->randomElement(['1 tablet', '5 ml', '1 application']),
            'route' => $faker->randomElement(['Oral', 'Injection', 'Topical', 'IV']),
            'frequency' => $faker->randomElement(['Once daily', 'Twice daily', 'Every 12 hours']),
            'duration_days' => $faker->numberBetween(5, 14),
            'start_date' => $recordedAt->format('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime($recordedAt->format('Y-m-d') . ' + ' . $faker->numberBetween(5, 14) . ' days')),
            'quantity_dispensed' => 1,
            'inventory_item_id' => $inventoryItemId,
            'special_instructions' => 'Administer with food and observe for response to treatment.',
        ],
        'deworming' => $payload + [
            'dewormer_name' => $faker->randomElement(['Drontal', 'Caniverm', 'Nemex', 'Endogard']),
            'brand' => $faker->randomElement(['Bayer', 'Virbac', 'Zoetis']),
            'dosage' => $faker->randomElement(['1 tablet', '5 ml oral suspension', '0.5 tablet']),
            'weight_at_treatment_kg' => round($weight, 2),
            'next_due_date' => date('Y-m-d', strtotime($recordedAt->format('Y-m-d') . ' + ' . $faker->numberBetween(60, 120) . ' days')),
        ],
        default => throw new RuntimeException('Unsupported medical procedure type for seeding: ' . $type),
    };
}

function phMobile(Generator $faker): string
{
    return '09' . str_pad((string) $faker->numberBetween(100000000, 999999999), 9, '0', STR_PAD_LEFT);
}
