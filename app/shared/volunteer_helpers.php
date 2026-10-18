<?php
// ============================================================
//  VOLUNTEER_HELPERS.PHP  (app/shared/)
//  Helper functions for volunteer program eligibility & verification
// ============================================================
require_once __DIR__ . '/election_helpers.php';

if (!function_exists('checkVolunteerEligibility')) {
    function checkVolunteerEligibility($studentCourse, $clubCategory, $clubProgram, $clubSubCat, $targetProgram = 'All') {
        $sp = normalizeProgram($studentCourse);

        // 1. If explicitly open to all programs
        if (empty($targetProgram) || strcasecmp($targetProgram, 'All') === 0 || strcasecmp($targetProgram, 'Open') === 0) {
            // If club itself is academic with a strict program and not advocacy/cultural
            if (strtolower($clubCategory ?? '') === 'academic' && !empty($clubProgram) && strtolower($clubProgram) !== 'institutional') {
                return checkElectionEligibility($studentCourse, $clubCategory, $clubProgram, $clubSubCat);
            }
            return ['eligible' => true, 'reason' => 'Open to All Enrolled Programs'];
        }

        // 2. Comma-separated or slash-separated target programs (e.g. "BSIT, BSCS, BSIS")
        $delim = strpos($targetProgram, '/') !== false ? '/' : ',';
        $targets = array_map(function($t) { return strtoupper(trim($t)); }, explode($delim, $targetProgram));

        if (in_array(strtoupper($sp['code']), $targets)) {
            return ['eligible' => true, 'reason' => "Enrolled in {$sp['code']} Program"];
        }

        // Check discipline/department match
        foreach ($targets as $t) {
            if (!empty($sp['disc']) && (stripos($sp['disc'], $t) !== false || stripos($t, $sp['disc']) !== false)) {
                return ['eligible' => true, 'reason' => "Enrolled in {$sp['disc']} Department"];
            }
            // IT / CS Cross match
            if (in_array($sp['code'], ['BSIT', 'BSCS', 'BSIS']) && (in_array($t, ['BSIT', 'BSCS', 'BSIS', 'CS', 'IT', 'COMPUTER STUDIES']))) {
                return ['eligible' => true, 'reason' => "Enrolled in {$sp['code']} (College of Computer Studies)"];
            }
        }

        return [
            'eligible' => false,
            'reason' => "This role is reserved for {$targetProgram} students. Your current program is {$sp['code']}."
        ];
    }
}
