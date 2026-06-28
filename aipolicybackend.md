EPESO Backend — AI Coding Policy
This file tells Claude (in Claude Code or any other session) exactly how to write backend code for this project. Read this BEFORE writing or editing any PHP file in epeso-backend/. Do not deviate from these rules unless the user explicitly asks for a one-off exception.

Every file produced under this policy must be clean, readable, and secured — consistent spacing/indentation matching Section 3 and 6, no unexplained shortcuts, and the Security Requirements in Section 8 applied without exception, not just "when it's convenient."

Every code block in this file is a FORMAT reference, not literal code to copy. Table names (gip_profiles), column names (school_name, course_degree), action names (createProfile), and variable names tied to those columns are all EXAMPLES from one sample table. When writing a real file, match the structure/spacing/pattern shown — but always swap in the actual table name, real primary key, and real column list for whatever table you're building. Never paste these examples in as-is.

1. Language & Style
PHP, procedural only. No classes, no new, no OOP. Every file is just top-to-bottom functions and logic.
Database access: PDO only. Do not use pg_connect, pg_query, pg_query_params, or any other pg_* function. Always use PDO.
Database: PostgreSQL. Connection string uses the pgsql: DSN prefix.
2. Routing Pattern (every API file follows this)
Rule: one file per module, not per table. A module file (e.g. gip.php) handles EVERY table that belongs to that module (e.g. gip_profiles AND gip_batches), not just one. Do not split a module into multiple files, and do not create a new file for a new table unless it's a genuinely new module.

Every file is one big switch ($action) block. The $action value must encode BOTH the operation and the table, because a module file often handles more than one table.

Naming pattern: {operation}{TableName}

?action=getAllProfiles      → SELECT all rows from the profiles table
?action=getProfileById      → SELECT one row from the profiles table
?action=createProfile       → INSERT into the profiles table
?action=updateProfile       → UPDATE the profiles table
?action=deleteProfile       → DELETE from the profiles table

?action=getAllBatches       → same pattern, but for the batches table
?action=createBatch
...
If a module only has ONE table (e.g. documents.php could arguably need this for documents and folders separately), still include the table name in the action for consistency — e.g. getAllDocuments, not just getAll.

3. Required Top of Every API File
Every file inside api/ must start with exactly this, in this order:

<?php

    include_once 'cors.php';
    include_once 'db_connect.php';

    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    switch ($action) {

        // cases go here

        default:

            $response = array(
                'status' => 'error',
                'message' => 'Invalid or missing action.'
            );

            echo json_encode($response);

    }

?>
4. JSON Response Format (always follow this shape)
Success — single object:

{
  "status": "success",
  "message": "Profile created",
  "data": { "gip_profile_id": 5 }
}
Success — list of rows:

{
  "status": "success",
  "data": [ { "...": "..." }, { "...": "..." } ]
}
Error:

{
  "status": "error",
  "message": "Profile not found"
}
How to build it in code — always use $response = array(...) then echo json_encode($response) as two separate steps, never inline:

$response = array(
    'status' => 'success',
    'message' => 'Profile created successfully.',
    'data' => ['gip_profile_id' => $newId]
);

echo json_encode($response);
Rules:

Always include 'status' as either 'success' or 'error'. Never omit it.
Use 'data' for the actual payload (single object OR array). Don't put rows directly at the top level — always nest under 'data'.
Use 'message' for human-readable context (required on error, optional but encouraged on success).
Build the response as $response = array(...), then echo json_encode($response) on its own line. Never echo a raw PDO result, resource, or array directly.
5. PDO Connection Pattern (config/db_connect.php)
<?php

    $host     = "127.0.0.1";
    $port     = "5432";
    $dbname   = "e-peso_db";
    $user     = "postgres";
    $password = getenv('DB_PASSWORD') ?: "your_password_here";

    try {

        $connection = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed. Please try again later.'
        ]);
        exit;

    }

?>
This creates $connection. Every API file uses this same $connection variable — never create a second connection inside an API file.

Why getenv('DB_PASSWORD') instead of a hardcoded string: a plain-text password sitting directly in a .php file is a real credential-leak risk — misconfigured Apache, a stray .bak file in a public folder, or an accidental commit to a public repo would expose it as-is. Pulling from an environment variable (set via a .env file, ignored by git) keeps the real password out of any file Claude Code writes or that gets shared/committed. The hardcoded fallback ("your_password_here") only exists so local dev still works before .env is set up — replace it with a real env setup before this goes anywhere near deployment.

Why the catch block doesn't return $e->getMessage() directly: same reasoning as Section 8.5 — a connection-failure message can leak host, port, or credential-shape detail. Keep the detailed message in a server-side log, not in the JSON response.

5.1 shared/cors.php
<?php

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }

?>
Required by every API file, included BEFORE db_connect.php. See Section 8.6 for the deployment-time change this needs (replacing * with a real domain).

6. CRUD Pattern Inside Each case (using PDO, procedural style)
One full module file, all five operations inside a single switch:

<?php

    include_once 'cors.php';
    include_once 'db_connect.php';

    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    switch ($action) {

        case 'getAllProfiles':

            $sql = "SELECT * FROM gip_profiles ORDER BY gip_profile_id ASC";
            $stmt = $connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $response = array(
                'status' => 'success',
                'data' => $rows
            );

            echo json_encode($response);
            break;

        case 'getProfileById':

            $id = $_GET['id'];

            $sql = "SELECT * FROM gip_profiles WHERE gip_profile_id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();

            if ($row) {
                $response = array(
                    'status' => 'success',
                    'data' => $row
                );
            } else {
                $response = array(
                    'status' => 'error',
                    'message' => 'Profile not found.'
                );
            }

            echo json_encode($response);
            break;

        case 'createProfile':

            $data = json_decode(file_get_contents("php://input"), true);

            $school_name   = $data['school_name'];
            $course_degree = $data['course_degree'];

            $sql = "INSERT INTO gip_profiles (school_name, course_degree) 
                    VALUES (:school_name, :course_degree) 
                    RETURNING gip_profile_id";

            $stmt = $connection->prepare($sql);
            $result = $stmt->execute([
                ':school_name'   => $school_name,
                ':course_degree' => $course_degree
            ]);

            if ($result) {
                $newId = $stmt->fetchColumn();
                $response = array(
                    'status' => 'success',
                    'message' => 'Profile created successfully.',
                    'data' => ['gip_profile_id' => $newId]
                );
            } else {
                $response = array(
                    'status' => 'error',
                    'message' => 'Failed to create profile.'
                );
            }

            echo json_encode($response);
            break;

        case 'updateProfile':

            $data = json_decode(file_get_contents("php://input"), true);

            $id            = $data['id'];
            $school_name   = $data['school_name'];
            $course_degree = $data['course_degree'];

            $sql = "UPDATE gip_profiles 
                    SET school_name = :school_name, course_degree = :course_degree 
                    WHERE gip_profile_id = :id";

            $stmt = $connection->prepare($sql);
            $result = $stmt->execute([
                ':school_name'   => $school_name,
                ':course_degree' => $course_degree,
                ':id'            => $id
            ]);

            if ($result) {
                $response = array(
                    'status' => 'success',
                    'message' => 'Profile updated successfully.'
                );
            } else {
                $response = array(
                    'status' => 'error',
                    'message' => 'Failed to update profile.'
                );
            }

            echo json_encode($response);
            break;

        case 'deleteProfile':

            $data = json_decode(file_get_contents("php://input"), true);
            $id = $data['id'];

            $sql = "DELETE FROM gip_profiles WHERE gip_profile_id = :id";
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute([':id' => $id]);

            if ($result) {
                $response = array(
                    'status' => 'success',
                    'message' => 'Profile deleted successfully.'
                );
            } else {
                $response = array(
                    'status' => 'error',
                    'message' => 'Failed to delete profile.'
                );
            }

            echo json_encode($response);
            break;

        default:

            $response = array(
                'status' => 'error',
                'message' => 'Invalid or missing action.'
            );

            echo json_encode($response);

    }

?>
Remember: gip_profiles, gip_profile_id, school_name, course_degree are EXAMPLES — swap in the real table name, real primary key, and real columns for whatever table you're actually building (see the note at the top of this file).

7. Hard Rules — Do Not Break These
Always use named placeholders (:id, :name) with prepare() + execute([...]). Never insert variables directly into an SQL string.
Always check $result/$row after execute()/fetch() and branch into a success or error $response array accordingly — see Section 6 for the exact if ($result) { ... } else { ... } shape.
Always use RETURNING on INSERT when you need the new id back — never use lastInsertId() for PostgreSQL.
Every response goes through $response = array(...) then echo json_encode($response) in the 'status'/'data'/'message' shape from Section 4. No exceptions, no raw print_r, no raw arrays echoed directly.
No business logic outside the switch. Keep each file flat, no helper classes, no separate function files unless the user explicitly asks for a shared procedural helper.
Never hardcode credentials anywhere except config/db_connect.php, and prefer getenv() there over a literal string — see Section 5.
Match column names exactly to the real schema in e-peso-db.sql — don't guess or invent column names.
8. Security Requirements (apply to every case, every file)
These are not optional add-ons — every create, update, and delete case must include them. A case that's missing these is incomplete, not "simpler."

8.1 Validate required fields before using them
Check that every field the query depends on actually exists and isn't empty BEFORE building the SQL or calling execute(). Missing-field bugs should return a clean 400 JSON error, never a PHP warning or a silent NULL written to the database.

case 'createProfile':

    $data = json_decode(file_get_contents("php://input"), true);

    $required = ['school_name', 'course_degree'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $response = array(
                'status' => 'error',
                'message' => "Missing required field: $field"
            );
            echo json_encode($response);
            exit;
        }
    }

    // ...proceed with INSERT only after the check above passes
Same pattern applies to id in getProfileById, updateProfile, and deleteProfile — check it exists before using it in any query.

8.2 Validate type/shape, not just presence
id values from $_GET or JSON body arrive as strings — cast or validate before trusting them, especially for anything used in a WHERE clause.

if (!isset($data['id']) || !is_numeric($data['id'])) {
    $response = array(
        'status' => 'error',
        'message' => 'Invalid or missing id.'
    );
    echo json_encode($response);
    exit;
}

$id = (int) $data['id'];
8.3 Never trust raw input directly into SQL
Named placeholders (:id, :name) already protect against SQL injection — keep using them for every value, with no exceptions, even for values that "look safe" (booleans, numbers, enum-like strings).

The ONLY thing that can never be a placeholder is a table or column name. Table/column names must always be hardcoded in the SQL string, never built from $_GET, $_POST, or any user-controlled value.

8.4 Password fields — never plain text, never returned
Any table with a password or password_hash column:

Hash before insert, using password_hash($plainPassword, PASSWORD_DEFAULT). Never write a plain-text password to the database.
Never SELECT it back to the frontend. Explicitly list columns instead of SELECT * on any table containing a password field.
Verify with password_verify(), never with = comparison.
case 'createUser':

    $data = json_decode(file_get_contents("php://input"), true);

    $hashed = password_hash($data['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (username, password_hash) 
            VALUES (:username, :password_hash) 
            RETURNING user_id";

    $stmt = $connection->prepare($sql);
    $stmt->execute([
        ':username'      => $data['username'],
        ':password_hash' => $hashed
    ]);
8.5 Don't leak internal error detail to the response
If you ever need to surface a caught database error (for example while debugging locally), don't put the raw driver message straight into the response — it can reveal table/column names or query structure. Keep the detailed message in a server-side log instead, and return something generic in the $response array.

$response = array(
    'status' => 'error',
    'message' => 'Something went wrong. Please try again.'
    // log the real error detail server-side instead of returning it directly
);

echo json_encode($response);
8.6 Validate foreign keys that point outside the current module
Several tables hold foreign keys to tables that live in a DIFFERENT module file. Example: documents (documents.php) has beneficiary_id (points to beneficiaries, in beneficiaries.php) and uploaded_by (points to users, in users-auth.php).

Before inserting/updating a row with a cross-module foreign key, verify the referenced row actually exists — don't rely only on the database's own FK constraint to catch it, because a raw constraint violation becomes an unhandled PDO error rather than a clean validation message.

case 'createDocument':

    $data = json_decode(file_get_contents("php://input"), true);

    $beneficiary_id = $data['beneficiary_id'];

    // beneficiary_id belongs to a DIFFERENT module (beneficiaries.php),
    // so check it exists here before inserting.
    $checkSql = "SELECT beneficiary_id FROM beneficiaries WHERE beneficiary_id = :id";
    $checkStmt = $connection->prepare($checkSql);
    $checkStmt->execute([':id' => $beneficiary_id]);

    if (!$checkStmt->fetch()) {
        $response = array(
            'status' => 'error',
            'message' => 'Invalid beneficiary_id: no matching beneficiary found.'
        );
        echo json_encode($response);
        exit;
    }

    // ...proceed with INSERT into documents only after the check above passes
Apply this check for every foreign key column that references a table outside the current module file. Foreign keys that stay WITHIN the same module (e.g. gip_profiles.batch_id → gip_batches, both in gip.php) need the same check, just easier to remember since both tables are in the same file.

8.8 Validate enum columns against their allowed values
Many columns use a PostgreSQL ENUM type with a fixed list of allowed strings (e.g. beneficiary_status_enum only allows 'Active' or 'Inactive'). PostgreSQL will reject an invalid value at the database level, but that surfaces as a raw, hard-to-read error — check it yourself first so the response stays a clean, specific message under Section 8.5.

case 'updateBeneficiary':

    $data = json_decode(file_get_contents("php://input"), true);

    $allowedStatuses = ['Active', 'Inactive']; // matches beneficiary_status_enum exactly

    if (!in_array($data['status'], $allowedStatuses)) {
        $response = array(
            'status' => 'error',
            'message' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)
        );
        echo json_encode($response);
        exit;
    }

    // ...proceed with UPDATE only after the check above passes
The allowed values for each enum are defined at the top of e-peso-db.sql as CREATE TYPE ... AS ENUM (...) — look up the exact list for whichever enum column you're validating, don't guess the values.

8.9 Verifying real column names before writing a case
Never guess a table's columns from the table name alone — two tables can have very similar-sounding columns that aren't actually identical (e.g. beneficiary_id exists on beneficiaries, documents, educations, skills, and several others, but each table's FULL column list is different).

Before writing any case for a table you haven't built yet:

Open e-peso-db.sql and find that table's CREATE TABLE public.<name> (...) block.
Copy the exact column names and types from that block.
Use those exact names in your SELECT/INSERT/UPDATE — never invent a column name that "sounds right" based on a similar table elsewhere.
If a column name is genuinely unclear from the dump alone (e.g. ambiguous purpose, no obvious matching frontend field), ask the user rather than guessing.

9. When Adding a New Module or Table
Confirm the module file already exists per Section 2 — if not, ask the user before creating a new top-level api/*.php file.
If the module file already exists, add new case blocks to its existing switch — don't create a second file for the same module.
Use the exact action naming pattern from Section 2.
Follow the CRUD code shape from Section 6 exactly, just swapping table name, primary key column, and field list.