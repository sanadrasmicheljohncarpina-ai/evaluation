<?php
/**
 * Shared evaluation-context business rules.
 *
 * A person's base Teacher/Staff function and their additional Multi-Role
 * responsibility are separate evaluation contexts.  Teaching assignments
 * control the visibility of the base Teacher/Staff context; they do NOT
 * create or remove Multi-Role.
 */

function ec_designation_tokens(string $designation): array {
    $designation = trim($designation);
    if ($designation === '') return [];
    $parts = preg_split('/\s*[,\/|;]+\s*/', $designation);
    return array_values(array_filter(array_map('trim', $parts ?: []), fn($t) => $t !== ''));
}

function ec_has_teacher_function(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    return in_array($role, ['teacher','faculty'], true) || $secondary === 'teacher';
}

function ec_has_staff_function(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    return $role === 'staff' || $secondary === 'staff';
}

function ec_has_additional_role(array $user): bool {
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    if ($secondary !== '' && !in_array($secondary, ['teacher','staff'], true)) {
        return true;
    }

    $designation = trim((string)($user['designation'] ?? ''));
    if ($designation === '') return false;

    $tokens = ec_designation_tokens($designation);
    if (count($tokens) > 1) return true;

    $d = strtolower($designation);

    // A single explicit responsibility is enough to create a Multi-Role
    // evaluation context.  This intentionally supports custom titles.
    $additional_markers = [
        'coordinator', 'committee', 'department head', 'program head',
        'project head', 'project coordinator', 'executive assistant',
        'student activit', 'physical plant', 'computer lab', 'custodian',
        'chair', 'director', 'manager', 'officer', 'adviser', 'advisor',
        'lead', 'supervisor', 'formation services', 'sports program',
        'yearbook', 'sdrmm', 'scholarship', 'admission',
    ];
    foreach ($additional_markers as $marker) {
        if (strpos($d, $marker) !== false) return true;
    }

    // Common base-only designations are not Multi-Role by themselves.
    return false;
}

function ec_context_label(string $context): string {
    return [
        'teacher' => 'Teacher',
        'staff' => 'Staff',
        'multi_role' => 'Multi-Role',
        'school_head' => 'School Head',
    ][$context] ?? ucwords(str_replace('_', ' ', $context));
}
