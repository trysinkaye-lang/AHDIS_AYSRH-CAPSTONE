<?php
declare(strict_types=1);

if (!function_exists('ahdisEducationNormalizeKey')) {
    function ahdisEducationNormalizeKey(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\xC2\xA0", '/', '\\'], [' ', ' ', ' '], $value);
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}

if (!function_exists('ahdisEducationAttainmentOptions')) {
    function ahdisEducationAttainmentOptions(): array
    {
        return [
            'No Education',
            'Elementary',
            'Junior High School',
            'Senior High School',
            'Vocational',
            'College Level',
        ];
    }
}

if (!function_exists('ahdisNormalizeEducationAttainment')) {
    function ahdisNormalizeEducationAttainment(?string $value): string
    {
        $key = ahdisEducationNormalizeKey($value);
        if ($key === '') {
            return '';
        }

        $aliases = [
            'no education' => 'No Education',
            'none' => 'No Education',
            'elementary' => 'Elementary',
            'junior hs' => 'Junior High School',
            'junior high' => 'Junior High School',
            'junior high school' => 'Junior High School',
            'jhs' => 'Junior High School',
            'senior hs' => 'Senior High School',
            'senior high' => 'Senior High School',
            'senior high school' => 'Senior High School',
            'shs' => 'Senior High School',
            'vocational' => 'Vocational',
            'technical vocational' => 'Vocational',
            'tvet' => 'Vocational',
            'tesda' => 'Vocational',
            'college' => 'College Level',
            'college level' => 'College Level',
            'college graduate' => 'College Level',
            'college level graduate' => 'College Level',
            'graduate' => 'College Level',
        ];

        return $aliases[$key] ?? '';
    }
}

if (!function_exists('ahdisEducationLevelOptions')) {
    function ahdisEducationLevelOptions(?string $educationalAttainment): array
    {
        $attainment = ahdisNormalizeEducationAttainment($educationalAttainment);

        $options = [
            'No Education' => [],
            'Elementary' => [
                'Grade 1',
                'Grade 2',
                'Grade 3',
                'Grade 4',
                'Grade 5',
                'Grade 6',
            ],
            'Junior High School' => [
                'Grade 7',
                'Grade 8',
                'Grade 9',
                'Grade 10',
            ],
            'Senior High School' => [
                'Grade 11',
                'Grade 12',
            ],
            'Vocational' => [],
            'College Level' => [],
        ];

        return $options[$attainment] ?? [];
    }
}

if (!function_exists('ahdisPartnerEducationLevelOptions')) {
    function ahdisPartnerEducationLevelOptions(?string $educationalAttainment): array
    {
        $attainment = ahdisNormalizeEducationAttainment($educationalAttainment);

        if (in_array($attainment, ['Elementary', 'Junior High School', 'Senior High School'], true)) {
            return ahdisEducationLevelOptions($attainment);
        }

        if ($attainment === 'Vocational') {
            return [
                'Vocational Training',
            ];
        }

        if ($attainment === 'College Level') {
            return [
                '1st Year College',
                '2nd Year College',
                '3rd Year College',
                '4th Year College',
                '5th Year College',
            ];
        }

        return [];
    }
}

if (!function_exists('ahdisNormalizeGradeYearLevel')) {
    function ahdisNormalizeGradeYearLevel(?string $educationalAttainment, ?string $gradeYearLevel): string
    {
        $attainment = ahdisNormalizeEducationAttainment($educationalAttainment);
        $value = trim((string)$gradeYearLevel);
        if ($value === '' || $attainment === 'No Education') {
            return '';
        }

        if ($attainment === 'Vocational' || $attainment === 'College Level') {
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            return trim($value);
        }

        $allowed = ahdisEducationLevelOptions($attainment);
        $allowedByKey = [];
        foreach ($allowed as $option) {
            $allowedByKey[ahdisEducationNormalizeKey($option)] = $option;
        }

        return $allowedByKey[ahdisEducationNormalizeKey($value)] ?? '';
    }
}

if (!function_exists('ahdisNormalizePartnerGradeYearLevel')) {
    function ahdisNormalizePartnerGradeYearLevel(?string $educationalAttainment, ?string $gradeYearLevel): string
    {
        $attainment = ahdisNormalizeEducationAttainment($educationalAttainment);
        $value = trim((string)$gradeYearLevel);
        if ($value === '' || $attainment === 'No Education') {
            return '';
        }

        $allowed = ahdisPartnerEducationLevelOptions($attainment);
        $allowedByKey = [];
        foreach ($allowed as $option) {
            $allowedByKey[ahdisEducationNormalizeKey($option)] = $option;
        }

        return $allowedByKey[ahdisEducationNormalizeKey($value)] ?? '';
    }
}

if (!function_exists('ahdisLastSchoolAttendedTypeOptions')) {
    function ahdisLastSchoolAttendedTypeOptions(): array
    {
        return [
            'Public',
            'Private',
        ];
    }
}

if (!function_exists('ahdisNormalizeLastSchoolAttendedType')) {
    function ahdisNormalizeLastSchoolAttendedType(?string $value): string
    {
        $key = ahdisEducationNormalizeKey($value);
        $aliases = [
            'public' => 'Public',
            'private' => 'Private',
            'not applicable' => 'Not Applicable',
            'na' => 'Not Applicable',
            'n a' => 'Not Applicable',
        ];

        return $aliases[$key] ?? '';
    }
}

if (!function_exists('ahdisReasonNotAttendingSchoolOptions')) {
    function ahdisReasonNotAttendingSchoolOptions(): array
    {
        return [
            'Currently attending school',
            'Financial problem',
            'Family problem',
            'Pregnancy / early parenthood',
            'Work / employment',
            'Lack of interest',
            'Illness / health condition',
            'School is too far',
            'Bullying / safety concern',
            'No available school documents',
            'Not applicable',
            'Other',
        ];
    }
}

if (!function_exists('ahdisNormalizeReasonNotAttendingSchool')) {
    function ahdisNormalizeReasonNotAttendingSchool(?string $value): string
    {
        $key = ahdisEducationNormalizeKey($value);
        if ($key === '') {
            return '';
        }

        $aliases = [
            'currently attending school' => 'Currently attending school',
            'attending school' => 'Currently attending school',
            'financial problem' => 'Financial problem',
            'financial problems' => 'Financial problem',
            'family problem' => 'Family problem',
            'family problems' => 'Family problem',
            'pregnancy early parenthood' => 'Pregnancy / early parenthood',
            'pregnancy' => 'Pregnancy / early parenthood',
            'early parenthood' => 'Pregnancy / early parenthood',
            'work employment' => 'Work / employment',
            'work' => 'Work / employment',
            'employment' => 'Work / employment',
            'lack of interest' => 'Lack of interest',
            'illness health condition' => 'Illness / health condition',
            'illness' => 'Illness / health condition',
            'health condition' => 'Illness / health condition',
            'school is too far' => 'School is too far',
            'far school' => 'School is too far',
            'bullying safety concern' => 'Bullying / safety concern',
            'bullying' => 'Bullying / safety concern',
            'safety concern' => 'Bullying / safety concern',
            'no available school documents' => 'No available school documents',
            'no school documents' => 'No available school documents',
            'not applicable' => 'Not applicable',
            'na' => 'Not applicable',
            'n a' => 'Not applicable',
            'other' => 'Other',
        ];

        return $aliases[$key] ?? trim((string)$value);
    }
}

if (!function_exists('ahdisNormalizeReasonsNotAttendingSchool')) {
    /**
     * Accept legacy single values, JSON arrays, imported delimited text, or
     * checkbox POST arrays and return a de-duplicated list of reasons.
     */
    function ahdisNormalizeReasonsNotAttendingSchool($value): array
    {
        $values = [];

        if (is_array($value)) {
            $values = $value;
        } elseif (is_scalar($value)) {
            $raw = trim((string)$value);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $values = $decoded;
                } else {
                    $values = preg_split('/\s*(?:;|\||\r\n|\r|\n)\s*/u', $raw) ?: [$raw];
                }
            }
        }

        $normalized = [];
        $seen = [];
        foreach ($values as $reason) {
            if (!is_scalar($reason)) {
                continue;
            }

            $canonical = ahdisNormalizeReasonNotAttendingSchool((string)$reason);
            if ($canonical === '') {
                continue;
            }

            $key = ahdisEducationNormalizeKey($canonical);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $canonical;
        }

        return $normalized;
    }
}

if (!function_exists('ahdisSerializeReasonsNotAttendingSchool')) {
    /**
     * Keep a single reason in its legacy scalar representation. Multiple
     * reasons use JSON inside the existing TEXT column (no schema migration).
     */
    function ahdisSerializeReasonsNotAttendingSchool($value): string
    {
        $reasons = ahdisNormalizeReasonsNotAttendingSchool($value);
        if (!$reasons) {
            return '';
        }
        if (count($reasons) === 1) {
            return $reasons[0];
        }

        $encoded = json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }
}

if (!function_exists('ahdisFormatReasonsNotAttendingSchool')) {
    function ahdisFormatReasonsNotAttendingSchool($value, string $separator = '; '): string
    {
        return implode($separator, ahdisNormalizeReasonsNotAttendingSchool($value));
    }
}

if (!function_exists('ahdisReasonsNotAttendingSchoolContains')) {
    function ahdisReasonsNotAttendingSchoolContains($value, string $reason): bool
    {
        $canonical = ahdisNormalizeReasonNotAttendingSchool($reason);
        return $canonical !== '' && in_array($canonical, ahdisNormalizeReasonsNotAttendingSchool($value), true);
    }
}

if (!function_exists('ahdisEducationClientConfig')) {
    function ahdisEducationClientConfig(): array
    {
        $levels = [];
        $partnerLevels = [];
        foreach (ahdisEducationAttainmentOptions() as $attainment) {
            $levels[$attainment] = ahdisEducationLevelOptions($attainment);
            $partnerLevels[$attainment] = ahdisPartnerEducationLevelOptions($attainment);
        }

        return [
            'attainmentOptions' => ahdisEducationAttainmentOptions(),
            'gradeYearOptions' => $levels,
            'partnerGradeYearOptions' => $partnerLevels,
            'lastSchoolOptions' => ahdisLastSchoolAttendedTypeOptions(),
            'reasonOptions' => ahdisReasonNotAttendingSchoolOptions(),
            'noEducationHelper' => 'Grade / Year Level is not required for No Education.',
            'gradeLabel' => 'Grade / Year Level',
            'vocationalLabel' => 'Vocational Course / Program',
            'collegeLabel' => 'Course / Program and Year Level',
            'vocationalPlaceholder' => 'Specify vocational course/program',
            'collegePlaceholder' => 'Specify course/program and year level',
        ];
    }
}

if (!function_exists('ahdisFamilyPlanningOptions')) {
    /**
     * Official Family Planning method questionnaire codes (0-10, 99).
     */
    function ahdisFamilyPlanningOptions(): array
    {
        return [
            '0' => 'None',
            '1' => 'Calendar / Standard Days Method',
            '2' => 'Withdrawal',
            '3' => 'IUD',
            '4' => 'Injectable',
            '5' => 'Implant',
            '6' => 'Pill',
            '7' => 'Condom',
            '8' => 'Sterilization',
            '9' => 'Lactational Amenorrhea Method (LAM)',
            '10' => 'Traditional Methods',
            
        ];
    }
}

if (!function_exists('ahdisNormalizeFamilyPlanning')) {
    /**
     * Normalize a stored/posted Family Planning value to its official code.
     * Legacy readable labels (e.g. "Injectable", "Pills", "Condom") map to
     * their official code. Unmappable legacy values are preserved as-is so
     * existing records are never lost.
     */
    function ahdisNormalizeFamilyPlanning($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $options = ahdisFamilyPlanningOptions();
        if (isset($options[$raw])) {
            return $raw;
        }

        if (preg_match('/^\s*(\d{1,2})\s*[-–—:)]\s*(.+)$/u', $raw, $m) && isset($options[$m[1]])) {
            return $m[1];
        }

        $key = ahdisEducationNormalizeKey($raw);
        if ($key === '') {
            return $raw;
        }

        $aliases = [
            '0' => '0',
            'none' => '0',
            'no method' => '0',
            'does not use' => '0',
            'not using' => '0',
            'not using any' => '0',
            '1' => '1',
            'calendar' => '1',
            'calendar method' => '1',
            'standard days method' => '1',
            'standard days' => '1',
            'sdm' => '1',
            'rhythm' => '1',
            'rhythm method' => '1',
            '2' => '2',
            'withdrawal' => '2',
            'pull out' => '2',
            'withdrawal method' => '2',
            '3' => '3',
            'iud' => '3',
            'intrauterine device' => '3',
            'copper t' => '3',
            '4' => '4',
            'injectable' => '4',
            'injectables' => '4',
            'injection' => '4',
            'depo provera' => '4',
            'depo' => '4',
            '5' => '5',
            'implant' => '5',
            'implants' => '5',
            '6' => '6',
            'pill' => '6',
            'pills' => '6',
            'contraceptive pill' => '6',
            'contraceptive pills' => '6',
            '7' => '7',
            'condom' => '7',
            'condoms' => '7',
            '8' => '8',
            'sterilization' => '8',
            'sterilized' => '8',
            'bilateral tubal ligation' => '8',
            'tubal ligation' => '8',
            'btl' => '8',
            'no scalpel vasectomy' => '8',
            'vasectomy' => '8',
            'nsv' => '8',
            '9' => '9',
            'lam' => '9',
            'lactational amenorrhea' => '9',
            'lactational amenorrhea method' => '9',
            'breastfeeding' => '9',
            '10' => '10',
            'traditional' => '10',
            'traditional method' => '10',
            'traditional methods' => '10',
            '99' => '99',
            'not applicable' => '99',
            'na' => '99',
            'n a' => '99',
        ];

        return $aliases[$key] ?? $raw;
    }
}

if (!function_exists('ahdisFamilyPlanningDisplay')) {
    /**
     * Render a stored Family Planning value (official code or legacy label)
     * as the readable official label.
     */
    function ahdisFamilyPlanningDisplay($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $options = ahdisFamilyPlanningOptions();
        if (isset($options[$raw])) {
            return $options[$raw];
        }

        $normalized = ahdisNormalizeFamilyPlanning($raw);
        return isset($options[$normalized]) ? $options[$normalized] : $raw;
    }
}
