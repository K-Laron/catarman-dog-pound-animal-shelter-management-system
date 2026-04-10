<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

use App\Core\Database;
use App\Helpers\IdGenerator;
use App\Models\Animal;
use Faker\Factory;
use Faker\Generator;

$count = 80;
$seed = random_int(100000, 999999);

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--seed=')) {
        $seed = max(1, (int) substr($argument, 7));
        continue;
    }

    if (ctype_digit($argument)) {
        $count = max(1, (int) $argument);
    }
}

$faker = Factory::create();
$faker->seed($seed);

$seedUser = Database::fetch(
    'SELECT id, username
     FROM users
     WHERE is_deleted = 0
       AND is_active = 1
     ORDER BY id ASC
     LIMIT 1'
);

if ($seedUser === false) {
    fwrite(STDERR, "No active user was found for created_by/updated_by.\n");
    exit(1);
}

$breedRows = Database::fetchAll('SELECT id, species, name FROM breeds ORDER BY species, name');
$availableKennels = Database::fetchAll(
    "SELECT id, kennel_code, zone, size_category, allowed_species
     FROM kennels
     WHERE is_deleted = 0
       AND status = 'Available'
     ORDER BY zone, kennel_code"
);

$breedsBySpecies = [];
foreach ($breedRows as $breedRow) {
    $breedsBySpecies[(string) $breedRow['species']][] = $breedRow;
}

$kennels = array_map(
    static fn (array $kennel): array => [
        'id' => (int) $kennel['id'],
        'kennel_code' => (string) $kennel['kennel_code'],
        'zone' => (string) $kennel['zone'],
        'size_category' => (string) $kennel['size_category'],
        'allowed_species' => (string) $kennel['allowed_species'],
    ],
    $availableKennels
);

$beforeCount = (int) (Database::fetch('SELECT COUNT(*) AS aggregate FROM animals WHERE is_deleted = 0')['aggregate'] ?? 0);
$assignableLimit = min(
    max(0, count($kennels) - 4),
    max(8, (int) ceil($count * 0.15))
);

$animalModel = new Animal();
$speciesCounts = [];
$statusCounts = [];
$assignedKennels = [];

Database::beginTransaction();

try {
    for ($index = 0; $index < $count; $index++) {
        $species = weightedPick([
            'Dog' => 50,
            'Cat' => 40,
            'Other' => 10,
        ], $faker);
        $size = weightedPick([
            'Small' => 32,
            'Medium' => 38,
            'Large' => 20,
            'Extra Large' => 10,
        ], $faker);
        $status = weightedPick([
            'Available' => 44,
            'Under Medical Care' => 18,
            'In Adoption Process' => 16,
            'Quarantine' => 10,
            'Adopted' => 6,
            'Transferred' => 4,
            'Deceased' => 2,
        ], $faker);
        $intakeType = weightedPick([
            'Stray' => 52,
            'Owner Surrender' => 20,
            'Confiscated' => 10,
            'Transfer' => 10,
            'Born in Shelter' => 8,
        ], $faker);

        $intakeDate = $faker->dateTimeBetween('-240 days', '-4 days');
        $statusChangedAt = $faker->dateTimeBetween($intakeDate, 'now');
        $ageYears = $faker->numberBetween(0, 12);
        $ageMonths = $ageYears === 0 ? $faker->numberBetween(1, 11) : $faker->numberBetween(0, 11);

        [$breedId, $breedOther] = resolveBreed($species, $breedsBySpecies, $faker);
        $payload = buildAnimalPayload(
            $faker,
            (int) $seedUser['id'],
            $species,
            $size,
            $status,
            $intakeType,
            $intakeDate,
            $statusChangedAt,
            $ageYears,
            $ageMonths,
            $breedId,
            $breedOther
        );

        $outcomeDate = $payload['outcome_date'];
        unset($payload['outcome_date']);

        $animalId = $animalModel->create($payload);

        if ($outcomeDate !== null) {
            Database::execute(
                'UPDATE animals
                 SET outcome_date = :outcome_date
                 WHERE id = :id',
                [
                    'id' => $animalId,
                    'outcome_date' => $outcomeDate,
                ]
            );
        }

        if (shouldAssignKennel($status) && count($assignedKennels) < $assignableLimit) {
            $kennel = takeMatchingKennel($kennels, $species, $size, $status);
            if ($kennel !== null) {
                $animalModel->assignKennel($animalId, $kennel['id'], (int) $seedUser['id']);
                $assignedKennels[] = $kennel['kennel_code'];
            }
        }

        $speciesCounts[$species] = ($speciesCounts[$species] ?? 0) + 1;
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    }

    Database::commit();
} catch (Throwable $exception) {
    Database::rollBack();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$afterCount = (int) (Database::fetch('SELECT COUNT(*) AS aggregate FROM animals WHERE is_deleted = 0')['aggregate'] ?? 0);

echo json_encode([
    'seed' => $seed,
    'requested_count' => $count,
    'created_count' => $count,
    'animals_before' => $beforeCount,
    'animals_after' => $afterCount,
    'assigned_kennels' => count($assignedKennels),
    'species' => $speciesCounts,
    'statuses' => $statusCounts,
], JSON_PRETTY_PRINT) . PHP_EOL;

function buildAnimalPayload(
    Generator $faker,
    int $seedUserId,
    string $species,
    string $size,
    string $status,
    string $intakeType,
    \DateTimeInterface $intakeDate,
    \DateTimeInterface $statusChangedAt,
    int $ageYears,
    int $ageMonths,
    ?int $breedId,
    ?string $breedOther
): array {
    $condition = weightedPick([
        'Healthy' => 42,
        'Injured' => 18,
        'Sick' => 18,
        'Malnourished' => 12,
        'Aggressive' => 10,
    ], $faker);
    $temperament = weightedPick([
        'Friendly' => 50,
        'Shy' => 22,
        'Aggressive' => 12,
        'Unknown' => 16,
    ], $faker);
    $gender = $faker->randomElement(['Male', 'Female']);
    $showLocationFound = $intakeType === 'Stray';
    $showSurrenderReason = $intakeType === 'Owner Surrender';
    $showBroughtBy = in_array($intakeType, ['Owner Surrender', 'Confiscated', 'Transfer'], true);
    $showAuthority = in_array($intakeType, ['Stray', 'Confiscated'], true);
    $microchipChance = $faker->numberBetween(1, 100);
    $outcomeStatuses = ['Adopted', 'Deceased', 'Transferred'];
    $weightBySize = [
        'Small' => [1.5, 8.0],
        'Medium' => [8.1, 18.0],
        'Large' => [18.1, 32.0],
        'Extra Large' => [32.1, 55.0],
    ];
    [$weightMin, $weightMax] = $weightBySize[$size];

    return [
        'animal_id' => IdGenerator::next('animal_id'),
        'name' => animalName($faker, $species, $gender),
        'species' => $species,
        'breed_id' => $breedId,
        'breed_other' => $breedOther,
        'gender' => $gender,
        'age_years' => $ageYears,
        'age_months' => $ageMonths,
        'color_markings' => $faker->randomElement([
            'Brown and white',
            'Black and tan',
            'Orange tabby',
            'Gray coat',
            'White paws',
            'Tricolor markings',
            'Brindle pattern',
            'Solid black',
        ]),
        'size' => $size,
        'weight_kg' => round($faker->randomFloat(2, $weightMin, $weightMax), 2),
        'distinguishing_features' => $faker->optional(0.55)->randomElement([
            'Scar on left ear',
            'Docked tail',
            'Blue collar mark',
            'Cloudy right eye',
            'Bent tail tip',
            'White blaze on chest',
            'Notched ear',
            'Mild limp on rear leg',
        ]),
        'special_needs_notes' => $faker->optional(0.22)->randomElement([
            'Needs gentle handling during feeding.',
            'Responds better to quiet spaces.',
            'Requires regular wound monitoring.',
            'Needs follow-up vaccination review.',
        ]),
        'microchip_number' => $microchipChance <= 28 ? '98514' . str_pad((string) $faker->numberBetween(0, 999999999), 9, '0', STR_PAD_LEFT) : null,
        'spay_neuter_status' => ($species !== 'Other' && $ageYears >= 1)
            ? weightedPick(['Yes' => 45, 'No' => 35, 'Unknown' => 20], $faker)
            : 'Unknown',
        'intake_type' => $intakeType,
        'intake_date' => $intakeDate->format('Y-m-d H:i:s'),
        'location_found' => $showLocationFound ? $faker->randomElement([
            'Barangay Aguada',
            'Barangay Baybay',
            'Barangay Poblacion East',
            'Barangay Poblacion West',
            'Barangay Yakal',
            'Near Catarman Public Market',
            'Outside municipal hall',
            'Riverside access road',
        ]) : null,
        'barangay_of_origin' => $faker->optional(0.75)->randomElement([
            'Aguada',
            'Baybay',
            'Cawayan',
            'Dalakit',
            'Ipil-Ipil',
            'Jose Abad Santos',
            'Molave',
            'Yakal',
        ]),
        'impoundment_order_number' => $showAuthority ? 'IO-' . $faker->numberBetween(2026000, 2026999) : null,
        'authority_name' => $showAuthority ? $faker->name() : null,
        'authority_position' => $showAuthority ? $faker->randomElement([
            'Municipal Veterinarian',
            'Barangay Official',
            'Animal Control Officer',
            'Public Safety Officer',
        ]) : null,
        'authority_contact' => $showAuthority ? phMobile($faker) : null,
        'brought_by_name' => $showBroughtBy ? $faker->name() : null,
        'brought_by_contact' => $showBroughtBy ? phMobile($faker) : null,
        'brought_by_address' => $showBroughtBy ? $faker->streetAddress() . ', Catarman, Northern Samar' : null,
        'impounding_officer_name' => $showAuthority ? $faker->name() : null,
        'surrender_reason' => $showSurrenderReason ? $faker->randomElement([
            'Owner relocation outside Northern Samar.',
            'Financial constraints for long-term care.',
            'Household can no longer accommodate the animal.',
            'Behavior needs exceed current household capacity.',
        ]) : null,
        'condition_at_intake' => $condition,
        'vaccination_status_at_intake' => weightedPick([
            'Unknown' => 38,
            'None' => 27,
            'Partial' => 20,
            'Up to date' => 15,
        ], $faker),
        'temperament' => $temperament,
        'status' => $status,
        'status_reason' => statusReason($status, $faker),
        'status_changed_at' => $statusChangedAt->format('Y-m-d H:i:s'),
        'outcome_date' => in_array($status, $outcomeStatuses, true)
            ? $statusChangedAt->format('Y-m-d H:i:s')
            : null,
        'created_by' => $seedUserId,
        'updated_by' => $seedUserId,
    ];
}

function resolveBreed(string $species, array $breedsBySpecies, Generator $faker): array
{
    if ($species === 'Other') {
        return [
            null,
            $faker->randomElement(['Rabbit', 'Goat', 'Pig', 'Chicken', 'Duck', 'Bird']),
        ];
    }

    $availableBreeds = $breedsBySpecies[$species] ?? [];
    if ($availableBreeds === [] || $faker->numberBetween(1, 100) > 82) {
        return [null, $faker->randomElement([
            $species . ' Mix',
            'Native ' . $species,
            'Rescued ' . $species,
        ])];
    }

    $breed = $availableBreeds[array_rand($availableBreeds)];

    return [(int) $breed['id'], null];
}

function shouldAssignKennel(string $status): bool
{
    return in_array($status, ['Available', 'Under Medical Care', 'In Adoption Process', 'Quarantine'], true);
}

function takeMatchingKennel(array &$kennels, string $species, string $size, string $status): ?array
{
    $preferredMatches = [];
    $fallbackMatches = [];

    foreach ($kennels as $index => $kennel) {
        $speciesMatch = $kennel['allowed_species'] === 'Any' || $kennel['allowed_species'] === $species;
        if (!$speciesMatch) {
            continue;
        }

        $zoneMatch = $status !== 'Quarantine' || $kennel['zone'] === 'Quarantine';
        $sizeMatch = $kennel['size_category'] === $size;

        if ($zoneMatch && $sizeMatch) {
            $preferredMatches[$index] = $kennel;
            continue;
        }

        if ($zoneMatch || $sizeMatch) {
            $fallbackMatches[$index] = $kennel;
        }
    }

    $pool = $preferredMatches !== [] ? $preferredMatches : $fallbackMatches;
    if ($pool === []) {
        return null;
    }

    $selectedIndex = array_rand($pool);
    $selected = $kennels[$selectedIndex];
    unset($kennels[$selectedIndex]);

    return $selected;
}

function animalName(Generator $faker, string $species, string $gender): string
{
    $dogNames = ['Bantay', 'Brownie', 'Lucky', 'Shadow', 'Max', 'Coco', 'Bruno', 'Milo', 'Rocky', 'Buddy'];
    $catNames = ['Muning', 'Mochi', 'Luna', 'Pepper', 'Oreo', 'Simba', 'Tofu', 'Mimi', 'Tiger', 'Nina'];
    $otherNames = ['Pip', 'Sunny', 'Bean', 'Patch', 'Piko', 'Cloud', 'Skye', 'Bubbles'];

    $pool = match ($species) {
        'Dog' => $dogNames,
        'Cat' => $catNames,
        default => $otherNames,
    };

    $name = $pool[array_rand($pool)];
    if ($faker->boolean(30)) {
        $name .= ' ' . strtoupper(substr($gender, 0, 1));
    }

    return $name;
}

function phMobile(Generator $faker): string
{
    return '09' . str_pad((string) $faker->numberBetween(100000000, 999999999), 9, '0', STR_PAD_LEFT);
}

function statusReason(string $status, Generator $faker): string
{
    $reasons = [
        'Available' => [
            'Cleared for shelter listing.',
            'Ready for matching and adoption.',
            'Observation completed and stable.',
        ],
        'Under Medical Care' => [
            'Recovering from intake-related illness.',
            'Under treatment and observation.',
            'Monitoring healing progress after procedure.',
        ],
        'In Adoption Process' => [
            'Reserved for approved adoption applicant.',
            'Currently matched with an adopter.',
            'Awaiting final adoption workflow completion.',
        ],
        'Quarantine' => [
            'Placed in quarantine for intake monitoring.',
            'Isolated pending veterinary clearance.',
            'Under quarantine due to recent exposure concern.',
        ],
        'Adopted' => [
            'Released to adopter after completion.',
            'Completed adoption workflow.',
        ],
        'Transferred' => [
            'Transferred to partner rescue.',
            'Moved to another facility for continued care.',
        ],
        'Deceased' => [
            'Recorded as deceased after critical decline.',
            'Loss recorded following veterinary intervention.',
        ],
    ];

    $pool = $reasons[$status] ?? ['Seeded animal record.'];

    return $pool[array_rand($pool)];
}

function weightedPick(array $weights, Generator $faker): string
{
    $total = array_sum($weights);
    $roll = $faker->numberBetween(1, $total);
    $cursor = 0;

    foreach ($weights as $value => $weight) {
        $cursor += $weight;
        if ($roll <= $cursor) {
            return (string) $value;
        }
    }

    return (string) array_key_first($weights);
}
