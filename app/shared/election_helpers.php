<?php
// ============================================================
//  ELECTION_HELPERS.PHP
//  Helper functions for program/course eligibility & voting
// ============================================================

if (!function_exists('normalizeProgram')) {
    function normalizeProgram($course) {
        $c = strtolower($course ?? '');
        if (strpos($c, 'information technology') !== false || strpos($c, 'bsit') !== false) return ['code' => 'BSIT', 'disc' => 'CS & IT'];
        if (strpos($c, 'computer science') !== false || strpos($c, 'bscs') !== false) return ['code' => 'BSCS', 'disc' => 'CS & IT'];
        if (strpos($c, 'information science') !== false || strpos($c, 'bsis') !== false) return ['code' => 'BSIS', 'disc' => 'CS & IT'];
        if (strpos($c, 'computer engineering') !== false || strpos($c, 'bscpe') !== false) return ['code' => 'BSCpE', 'disc' => 'Computer Engineering'];
        if (strpos($c, 'accounting') !== false || strpos($c, 'bsais') !== false) return ['code' => 'BSAIS', 'disc' => 'Accounting & Finance'];
        if (strpos($c, 'hospitality') !== false || strpos($c, 'bshm') !== false || strpos($c, 'hrm') !== false) return ['code' => 'BSHM', 'disc' => 'Hospitality & Culinary Arts'];
        if (strpos($c, 'tourism') !== false || strpos($c, 'bstm') !== false) return ['code' => 'BSTM', 'disc' => 'Tourism & Travel'];
        if (strpos($c, 'criminology') !== false || strpos($c, 'bscrim') !== false) return ['code' => 'BSCrim', 'disc' => 'Criminal Justice'];
        if (strpos($c, 'psychology') !== false || strpos($c, 'bspsych') !== false) return ['code' => 'BSPsych', 'disc' => 'Social Sciences'];
        if (strpos($c, 'business administration') !== false || strpos($c, 'bsba') !== false) return ['code' => 'BSBA', 'disc' => 'Business & Entrepreneurship'];
        if (strpos($c, 'entrepreneurship') !== false || strpos($c, 'bse') !== false) return ['code' => 'BSE', 'disc' => 'Business & Entrepreneurship'];
        if (strpos($c, 'office administration') !== false || strpos($c, 'bsoa') !== false) return ['code' => 'BSOA', 'disc' => 'Management & Administration'];
        if (strpos($c, 'education') !== false || strpos($c, 'bsed') !== false || strpos($c, 'beed') !== false || strpos($c, 'tle') !== false) return ['code' => 'BSEd', 'disc' => 'Education & Values'];
        if (strpos($c, 'library') !== false || strpos($c, 'blis') !== false) return ['code' => 'BLIS', 'disc' => 'Library & Information Science'];
        if (strpos($c, 'physical education') !== false || strpos($c, 'bspe') !== false) return ['code' => 'BSPE', 'disc' => 'Physical Education & Wellness'];
        return ['code' => strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $course ?? ''), 0, 6)), 'disc' => 'General'];
    }
}

if (!function_exists('checkElectionEligibility')) {
    function checkElectionEligibility($studentCourse, $clubCategory, $clubProgram, $clubSubCat) {
        if (strtolower($clubCategory ?? '') !== 'academic') {
            return ['eligible' => true, 'reason' => 'Campus-Wide / Non-Academic Organization'];
        }
        if (strtolower($clubProgram ?? '') === 'institutional' || empty($clubProgram)) {
            return ['eligible' => true, 'reason' => 'Institutional Academic Organization'];
        }
        $sp = normalizeProgram($studentCourse);
        $clubProgNorm = strtoupper(trim($clubProgram ?? ''));
        
        // Direct program code match
        if ($sp['code'] === $clubProgNorm) {
            return ['eligible' => true, 'reason' => "Enrolled in {$sp['code']} Program"];
        }
        // Discipline / Department match (e.g. BSIT student eligible for CS & IT)
        if (!empty($sp['disc']) && !empty($clubSubCat) && strcasecmp($sp['disc'], trim($clubSubCat)) === 0) {
            return ['eligible' => true, 'reason' => "Enrolled in {$sp['disc']} Department"];
        }
        // CS / IT cross-match
        if (in_array($sp['code'], ['BSIT', 'BSCS', 'BSIS']) && (in_array($clubProgNorm, ['BSIT', 'BSCS', 'BSIS']) || strcasecmp($clubSubCat ?? '', 'CS & IT') === 0)) {
            return ['eligible' => true, 'reason' => "Enrolled in {$sp['code']} ({$sp['disc']})"];
        }

        return [
            'eligible' => false,
            'reason' => "Restricted to {$clubProgram} (" . ($clubSubCat ?: 'Academic') . ") students only. Your program is {$sp['code']}."
        ];
    }
}
