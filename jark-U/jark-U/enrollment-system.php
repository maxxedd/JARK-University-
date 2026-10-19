<?php
/**
 * JARK University Enrollment System
 * OOP Core Classes - Updated Prerequisite Grade Logic
 */

class Student {
    public string $id;
    public string $firstName;
    public string $lastName;
    public string $program;
    public int $yearLevel;
    public string $enrollmentStatus;
    public string $email;
    public string $phone;
    public string $section;
    public string $address;
    public string $birthday;

    public function __construct(array $data) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) $this->$key = $value;
        }
    }

    /**
     * Resolves the 3 default core subject course IDs for this student's program and year level
     */
    public function getDefaultCoreSubjects(): array {
        $curriculumFile = __DIR__ . '/data/program_curriculum.json';
        if (!file_exists($curriculumFile)) return [];
        
        $curriculum = json_decode(file_get_contents($curriculumFile), true) ?: [];
        $sem = getActiveSemester();
        $semNum = $sem['semester'] ?? 1;
        
        // Find subject codes mapped to program, year level, and semester
        $subjectCodes = $curriculum[$this->program][$this->yearLevel][$semNum] ?? [];
        if (empty($subjectCodes)) {
            // Fallback: collect any generic codes
            $subjectCodes = isset($curriculum[$this->program][$this->yearLevel]) ? 
                array_merge(...array_values($curriculum[$this->program][$this->yearLevel])) : [];
        }
        
        $allCourses = readJSON(COURSES_FILE);
        $matchedIds = [];
        
        foreach ($allCourses as $course) {
            if (in_array($course['code'], $subjectCodes, true) || $course['program'] === $this->program) {
                $matchedIds[] = $course['id'];
                if (count($matchedIds) >= 3) break; // Hard limit to exactly 3 default subjects
            }
        }
        
        return $matchedIds;
    }

    public function enrollStudent(string $studentId, array $subjectIds): bool {
        foreach ($subjectIds as $courseId) {
            // Enforce Eligibility Check
            if (!isEligibleForCourse($studentId, $courseId)) {
                // Log error or set a flash message
                flash('error', "Cannot enroll in $courseId: Failed or missing prerequisite.");
                return false;
            }
        }
        
        // ... proceed with existing enrollment logic (e.g., saving to ENROLLMENTS_FILE)
        return true;
    }

    /**
     * Enroll student in multiple courses (respects capacity, passing prerequisite marks, and status windows)
     */
    public function enrollInCourse(Course $course, string $semesterId): array {
        if (!EnrollmentPeriod::isOpen()) {
            return ['success' => false, 'message' => 'Enrollment period is closed.'];
        }
        if ($course->enrolledCount >= $course->slots) {
            return ['success' => false, 'message' => "{$course->code} is full."];
        }
        if (!$course->checkPrerequisites($this->id)) {
            return ['success' => false, 'message' => "Prerequisites not met or previous passing grade requirement not satisfied for {$course->code}."];
        }
        
        // Find or build enrollment array records
        $enrollments = readJSON(ENROLLMENTS_FILE);
        $found = false;
        foreach ($enrollments as &$e) {
            if ($e['studentId'] === $this->id && $e['semesterId'] === $semesterId) {
                if (!in_array($course->id, $e['subjects'], true)) {
                    $e['subjects'][] = $course->id;
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            $enrollments[] = [
                'id' => 'ENR-' . str_pad(count($enrollments) + 1, 3, '0', STR_PAD_LEFT),
                'studentId' => $this->id,
                'semesterId' => $semesterId,
                'subjects' => [$course->id],
                'status' => 'pending',
                'submittedAt' => date('Y-m-d H:i:s'),
                'verifiedAt' => null,
                'verifiedBy' => null,
                'updatedAt' => date('Y-m-d H:i:s')
            ];
        }
        writeJSON(ENROLLMENTS_FILE, $enrollments);
        return ['success' => true, 'message' => "Successfully added {$course->code}."];
    }

    /**
     * Remove a subject from a pending enrollment
     */
    public function dropCourse(Course $course, string $semesterId): array {
        $enrollments = readJSON(ENROLLMENTS_FILE);
        foreach ($enrollments as &$e) {
            if ($e['studentId'] === $this->id && $e['semesterId'] === $semesterId) {
                if ($e['status'] === 'verified') {
                    return ['success' => false, 'message' => 'Cannot drop a verified enrollment.'];
                }
                $e['subjects'] = array_values(array_filter($e['subjects'], fn($sid) => $sid !== $course->id));
                $e['updatedAt'] = date('Y-m-d H:i:s');
                writeJSON(ENROLLMENTS_FILE, $enrollments);
                return ['success' => true, 'message' => "Successfully dropped {$course->code}."];
            }
        }
        return ['success' => false, 'message' => 'Enrollment record not found.'];
    }
}

class Course {
    public string $id;
    public string $code;
    public string $name;
    public array $prerequisites = []; // Array of prerequisite Subject Codes (e.g., ["CC102"])
    public int $slots;
    public int $enrolledCount;

    public function __construct(array $data) {
        $this->id = $data['id'] ?? '';
        $this->code = $data['code'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->prerequisites = $data['prerequisites'] ?? [];
        $this->slots = (int)($data['slots'] ?? 40);
        $this->enrolledCount = (int)($data['enrolled'] ?? 0);
    }

    public static function findById(string $id): ?Course {
        $courses = readJSON(COURSES_FILE);
        foreach ($courses as $c) {
            if ($c['id'] === $id) return new Course($c);
        }
        return null;
    }

    /**
     * Checks if a student satisfies all prerequisites with a legitimate passing grade value (<= 3.00)
     */
    public function checkPrerequisites(string $studentId): bool {
        if (empty($this->prerequisites)) return true;

        $grades = readJSON(GRADES_FILE);
        $courses = readJSON(COURSES_FILE);

        foreach ($this->prerequisites as $prereqCode) {
            $satisfied = false;
            foreach ($grades as $g) {
                if ($g['studentId'] === $studentId) {
                    // Match by direct course ID tracking or resolving the internal course code
                    $courseMatch = false;
                    if ($g['courseId'] === $prereqCode) {
                        $courseMatch = true;
                    } else {
                        foreach ($courses as $c) {
                            if ($c['id'] === $g['courseId'] && $c['code'] === $prereqCode) {
                                $courseMatch = true;
                                break;
                            }
                        }
                    }

                    if ($courseMatch) {
                        // In the academic scheme utilized, grades 1.00 down to 3.00 represent passing marks, 5.00 is failing.
                        $gradeValue = (float)$g['grade'];
                        if ($gradeValue > 0 && $gradeValue <= 3.00) {
                            $satisfied = true;
                        }
                    }
                }
            }
            if (!$satisfied) return false; // Immediate block the instant a requirement fails criteria
        }
        return true;
    }
}

class EnrollmentPeriod {
    public static function get(): array {
        $sem = getActiveSemester();
        return [
            'start' => $sem['enrollmentStart'] ?? '',
            'end'   => $sem['enrollmentEnd'] ?? ''
        ];
    }

    public static function isOpen(): bool {
        $period = self::get();
        if (empty($period['start']) || empty($period['end'])) return false;
        $today = date('Y-m-d');
        return $today >= $period['start'] && $today <= $period['end'];
    }

    public static function setDates(string $start, ?string $end = null): bool {
        $sem = getActiveSemester();
        if (!$sem) return false;
        
        $all = readJSON(SEMESTERS_FILE);
        $updated = false;
        foreach ($all as &$s) {
            if ($s['id'] === $sem['id']) {
                $s['enrollmentStart'] = $start;
                $s['enrollmentEnd'] = $end ?: date('Y-m-d', strtotime($start . ' + 7 days'));
                $updated = true;
                break;
            }
        }
        return $updated ? writeJSON(SEMESTERS_FILE, $all) : false;
    }

    public static function closeNow(): bool {
        $sem = getActiveSemester();
        if (!$sem) return false;
        
        $all = readJSON(SEMESTERS_FILE);
        $updated = false;
        foreach ($all as &$s) {
            if ($s['id'] === $sem['id']) {
                $s['enrollmentEnd'] = date('Y-m-d', strtotime('-1 day'));
                $updated = true;
                break;
            }
        }
        return $updated ? writeJSON(SEMESTERS_FILE, $all) : false;
    }
}