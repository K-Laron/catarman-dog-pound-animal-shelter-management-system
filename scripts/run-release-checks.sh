#!/usr/bin/env bash
set -e

invoke_step() {
    local label="$1"
    local command_str="$2"

    echo "==> $label"
    eval "$command_str"
    echo "<== $label complete"
}

invoke_step "Route tests" "php vendor/bin/phpunit tests/Routes"
invoke_step "Critical HTTP smoke tests" "php vendor/bin/phpunit tests/Integration/Http/AuthenticatedPageSmokeTest.php tests/Integration/Http/PublicAdoptionJourneyHttpTest.php tests/Integration/Http/ApiDashboardHttpTest.php"
invoke_step "Full PHPUnit suite" "php vendor/bin/phpunit"
invoke_step "Node tooling check" "npm run tooling:check"
invoke_step "Frontend smoke tests" "node tests/Frontend/animals-inline-photo-upload.test.js"
