<?php

declare(strict_types=1);

namespace App\Database\Seeds\Material;

/**
 * Database readings (IT101 introduction to data, IT201 Database Systems).
 * The running example is a small school: students, courses and enrolments.
 */
final class DatabaseReadings
{
    /** @return array<string, list<array{0: string, 1: string|list<string>}>> */
    public static function all(): array
    {
        return [
            'What Is a Database?' => self::whatIsADatabase(),
            'Your First SELECT Query' => self::firstSelect(),
            'Primary and Foreign Keys' => self::keys(),
        ];
    }

    private static function whatIsADatabase(): array
    {
        return [
            ['h', 'What Is a Database?'],
            ['p', 'Think about a school’s records. There are thousands of students, hundreds of courses, and every term brings new enrolments, new grades and corrections. Keeping all of that in separate spreadsheets and paper folders quickly turns into a mess: the same student’s address is written in five places, and when it changes, four of them are forgotten. A **database** is an organised collection of data, stored so that many people and programs can add to it, find things in it and change it safely and quickly. In this lesson you will learn why databases exist, how a relational database is organised, and what a database management system does for you.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Explain what a database is and why files and spreadsheets are not enough.',
                'Describe tables, rows (records) and columns (fields).',
                'Explain the role of a database management system (DBMS).',
                'Identify the kinds of operations performed on data: create, read, update and delete.',
                'Recognise where databases appear in everyday applications.',
            ]],
            ['h', 'The problem with scattered data'],
            ['p', 'Imagine the registrar keeps a spreadsheet of enrolments with one line per student per course. Each line repeats the student’s name, program and address. This causes several kinds of trouble that database designers call **anomalies**.'],
            ['ul', [
                '**Update anomaly.** A student moves house. The address must be changed on every line that mentions them, and missing one leaves two different addresses for the same person.',
                '**Insertion anomaly.** A new course is created, but nobody has enrolled yet, so there is no line on which to record it.',
                '**Deletion anomaly.** The only student in a course drops out and the line is deleted, and with it all knowledge that the course exists.',
                '**Inconsistency.** The same fact is spelled slightly differently in different places, so searches miss records.',
            ]],
            ['p', 'A database solves these by storing each fact **once**, in the right place, and by linking related facts together.'],
            ['h', 'Tables, rows and columns'],
            ['p', 'Most databases in use today are **relational**. Data is stored in **tables**, and a table is a grid. Each **column**, also called a **field**, describes one property, such as a name or a date of birth, and has a defined type. Each **row**, also called a **record**, is one thing described by those properties: one student, one course.'],
            ['ul', [
                'A **students** table might have the columns student_id, first_name, last_name and program.',
                'A **courses** table might have course_code, title and units.',
                'An **enrolments** table might have student_id, course_code and term.',
            ]],
            ['p', 'Notice that the enrolments table does not repeat the student’s name or the course title. It holds only the identifiers, and those point to the full details held once elsewhere. Pointing from one table to another is what makes the model “relational”.'],
            ['h', 'The database management system'],
            ['p', 'You rarely touch the data files of a database directly. A program called a **database management system**, or **DBMS**, sits between you and the data. Well-known examples are MySQL, MariaDB, PostgreSQL, SQL Server and SQLite. The DBMS takes care of many hard problems so that application authors do not have to.'],
            ['ul', [
                '**Storage and retrieval.** It decides how the data is laid out on the disk and finds it quickly.',
                '**Querying.** It understands a language, SQL, for asking questions about the data.',
                '**Integrity.** It enforces rules, such as “every enrolment must refer to a real student”.',
                '**Concurrency.** It lets many users work at the same time without corrupting each other’s changes.',
                '**Security.** It controls who may see or change what.',
                '**Recovery.** It can restore data after a crash or a mistake.',
            ]],
            ['h', 'What you can do with data'],
            ['p', 'Almost everything an application does with a database falls into four operations, often abbreviated **CRUD**.'],
            ['ol', [
                '**Create**: add a new record, such as registering a new student.',
                '**Read**: look records up, such as finding a student’s grades.',
                '**Update**: change an existing record, such as correcting a surname.',
                '**Delete**: remove a record, such as cancelling an enrolment.',
            ]],
            ['h', 'Databases around you'],
            ['p', 'Databases are everywhere you do not see them. A bank uses one to record every deposit and withdrawal. An online shop stores its products, customers and orders. A hospital keeps patient records. A social network stores your posts and the list of who follows whom. Even the learning platform you are using right now keeps its users, courses, readings and grades in a database, and every page you open is the result of a few queries.'],
            ['h', 'Worked example: designing from a sentence'],
            ['p', 'A small library says: “We lend books to members. Each book has a title and an author. Each member has a name and a phone number. We need to know who borrowed which book and when.” Identify the tables.'],
            ['ol', [
                'Find the things the sentence talks about: books, members and loans.',
                'Give each its own table: **books**, **members**, **loans**.',
                'List the facts for each thing: books have a title and an author; members have a name and a phone number.',
                'Notice that a loan connects a member and a book and has a date, so the loans table holds a member identifier, a book identifier and a date borrowed.',
                'Check that nothing is stored twice: a member’s phone number appears only in the members table.',
            ]],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Treating a database as a big spreadsheet** and repeating the same facts on many rows.',
                '**Putting several values in one field**, such as a list of phone numbers separated by commas, which makes searching and updating difficult.',
                '**Using a person’s name as the identifier.** Two students can share a name; each record needs an identifier of its own.',
                '**Confusing the database with the DBMS.** The database is the data; the DBMS is the software that manages it.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Database**: an organised collection of data.',
                '**DBMS**: software that manages a database.',
                '**Table**: a grid that stores one kind of thing.',
                '**Row / record**: one item in a table.',
                '**Column / field**: one property of the items in a table.',
                '**Relational**: organised as tables that are linked to one another.',
                '**CRUD**: create, read, update, delete.',
            ]],
            ['h', 'Summary'],
            ['p', 'A database stores data once, in organised tables, and a DBMS provides fast access, safety and rules. The relational model uses rows and columns and links tables through identifiers. In the next lesson you will begin asking questions of a database with the SELECT statement.'],
        ];
    }

    private static function firstSelect(): array
    {
        return [
            ['h', 'Your First SELECT Query'],
            ['p', 'Once data is in a database, the most common job is getting it out again: which students are in this program, what are the courses with three units, who has not paid. The language used to ask such questions is **SQL**, pronounced “S-Q-L” or “sequel”. It is the standard language of relational databases and it reads almost like English. In this lesson you will write your first queries with the SELECT statement, choose columns, filter rows, sort the results and limit how many are returned.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Write a SELECT statement that returns chosen columns from a table.',
                'Filter rows with WHERE using comparison and logical operators.',
                'Sort results with ORDER BY and restrict them with LIMIT.',
                'Use LIKE to match patterns in text.',
                'Read the result of a query as a table.',
            ]],
            ['h', 'The sample data'],
            ['p', 'All of the examples use a small **students** table with these columns: `student_id`, `first_name`, `last_name`, `program` and `gpa`. Suppose it holds a handful of rows, for example Ana Reyes in BSIT with a GPA of 3.60, Ben Cruz in BSCS with 3.10, Carla Santos in BSIT with 3.85, and Dino Lim in BSIT with 2.75.'],
            ['h', 'The basic shape'],
            ['p', 'A query has two required parts: what you want and where it comes from. **SELECT** names the columns; **FROM** names the table.'],
            ['code', 'SELECT first_name, last_name\nFROM students;'],
            ['p', 'The result is itself a table, with only those two columns and one row for every student. To get every column, use an asterisk.'],
            ['code', 'SELECT *\nFROM students;'],
            ['p', 'Asking for all columns is fine for exploring, but in a real program it is better to name the columns you need, because it is clearer and it keeps the amount of data transferred small.'],
            ['h', 'Filtering rows with WHERE'],
            ['p', 'Most of the time you do not want every row. The **WHERE** clause keeps only the rows for which a condition is true. This is the same idea as the conditions you wrote in programming.'],
            ['code', "SELECT first_name, last_name, gpa\nFROM students\nWHERE program = 'BSIT';"],
            ['p', 'Text values are written in single quotes. Numbers are not. You can combine conditions with **AND**, **OR** and **NOT**.'],
            ['code', "SELECT first_name, gpa\nFROM students\nWHERE program = 'BSIT' AND gpa >= 3.5;"],
            ['p', 'This returns the BSIT students with a GPA of at least 3.5, which from our sample are Ana and Carla. The comparison operators are `=`, `<>` (not equal), `<`, `<=`, `>` and `>=`. Note that SQL uses a single equals sign for comparison.'],
            ['h', 'Sorting with ORDER BY'],
            ['p', 'Rows have no guaranteed order unless you ask for one. **ORDER BY** sorts the result, ascending by default. Add **DESC** for descending.'],
            ['code', 'SELECT first_name, last_name, gpa\nFROM students\nORDER BY gpa DESC;'],
            ['p', 'You can sort by several columns, separated by commas. The second is used to break ties in the first.'],
            ['h', 'Limiting the result'],
            ['p', 'Sometimes you only want the top few. **LIMIT** restricts the number of rows. Combined with ORDER BY it answers questions such as “who are the top three students?”.'],
            ['code', 'SELECT first_name, last_name, gpa\nFROM students\nORDER BY gpa DESC\nLIMIT 3;'],
            ['h', 'Matching patterns with LIKE'],
            ['p', 'When you know only part of a value, **LIKE** compares text against a pattern. The percent sign stands for any number of characters.'],
            ['code', "SELECT first_name, last_name\nFROM students\nWHERE last_name LIKE 'S%';"],
            ['p', 'This returns every student whose last name begins with S. Use `%an%` to find values that contain “an” anywhere.'],
            ['h', 'Giving columns a friendlier name'],
            ['p', 'You can rename a column in the output with **AS**. This does not change the table; it only changes the heading of the result.'],
            ['code', "SELECT first_name AS given_name, gpa AS grade_point_average\nFROM students;"],
            ['h', 'Worked example: building a query step by step'],
            ['p', 'Task: list the names and GPA of the three highest-ranked BSIT students whose last name does not start with L.'],
            ['ol', [
                'Decide the columns: first_name, last_name, gpa.',
                'Decide the table: students.',
                'Decide the filter: program is BSIT, and last_name does not start with L.',
                'Decide the order: highest GPA first.',
                'Decide the limit: three rows.',
                "Assemble: SELECT first_name, last_name, gpa FROM students WHERE program = 'BSIT' AND last_name NOT LIKE 'L%' ORDER BY gpa DESC LIMIT 3;",
            ]],
            ['p', 'Building a query in the same order each time, columns, table, filter, order and limit, helps you avoid mistakes. Run it, look at the result, and compare it with what you expected.'],
            ['h', 'The order in which you write a query'],
            ['p', 'The clauses must appear in a fixed order: SELECT, FROM, WHERE, ORDER BY, LIMIT. Writing them in a different order is a syntax error. Keywords are not case sensitive, but writing them in capitals and putting each clause on its own line makes a query far easier to read.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Forgetting the quotes around text** in a condition, so the database looks for a column with that name.',
                '**Using double equals** (==) as in programming languages. SQL uses a single equals sign.',
                '**Comparing with NULL using =.** A missing value must be tested with IS NULL.',
                '**Expecting a particular order** without ORDER BY.',
                '**Missing the semicolon** that ends a statement when you run several at a time.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**SQL**: the language used to query relational databases.',
                '**SELECT**: chooses columns to return.',
                '**FROM**: names the table to read.',
                '**WHERE**: keeps only rows that match a condition.',
                '**ORDER BY**: sorts the result.',
                '**LIMIT**: restricts the number of rows.',
                '**LIKE**: matches text against a pattern.',
            ]],
            ['h', 'Summary'],
            ['p', 'A SELECT statement names the columns you want, the table they come from, and, optionally, a filter, an order and a limit. The result is always a table. Practise by changing one part of a query at a time and predicting the result before you run it. Next you will learn how rows in different tables are connected by keys.'],
        ];
    }

    private static function keys(): array
    {
        return [
            ['h', 'Primary and Foreign Keys'],
            ['p', 'In a relational database no table stands alone. A student is enrolled in courses, a course is taught by an instructor, an order contains products. To connect these things without copying information from one table to another, databases use **keys**. Keys do two jobs: they identify each row without any doubt, and they link rows in different tables. This lesson explains the two most important kinds, the primary key and the foreign key, and how they keep your data consistent.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Explain what a primary key is and what makes a good one.',
                'Explain what a foreign key is and how it links two tables.',
                'Describe one-to-many and many-to-many relationships.',
                'Explain referential integrity and what it prevents.',
                'Design a simple pair of related tables.',
            ]],
            ['h', 'Why every row needs an identity'],
            ['p', 'Suppose a school has two students named Maria Santos. If the only way to tell their records apart is by name, a query for “Maria Santos’s grades” returns both people’s grades mixed together, and an update to “her” address changes the wrong person. Every row must therefore carry something that distinguishes it from every other row in the table, and that something must never be absent or duplicated. This is the **primary key**.'],
            ['h', 'The primary key'],
            ['p', 'A **primary key** is a column, or a small group of columns, whose value uniquely identifies each row. The database enforces two rules: the value must be **unique**, and it must not be **NULL**. Because the key is dependable, other tables can safely refer to the row by it.'],
            ['p', 'Good primary keys have these qualities.'],
            ['ul', [
                '**Unique.** No two rows ever share it.',
                '**Stable.** It does not change over time. A surname or an email address can change, so they make poor keys.',
                '**Simple.** A single short value, usually a number, is faster and easier to use than a long text.',
                '**Meaningless is fine.** A generated number such as student_id 1042 does not have to describe anything.',
            ]],
            ['p', 'Most designs add a column such as `id` that the database fills in automatically with the next number. This is called an **auto-increment** key.'],
            ['h', 'The foreign key'],
            ['p', 'A **foreign key** is a column in one table that holds the primary key of a row in another table. It is how the link is made. In an enrolments table, the column `student_id` is a foreign key that points to the students table, and `course_code` is a foreign key that points to the courses table.'],
            ['p', 'For example, the row (student_id 1042, course_code IT201, term 1st Semester) in the enrolments table says that student 1042 is taking course IT201. The numbers 1042 and IT201 are copies of keys, not copies of the student or the course.'],
            ['p', 'Because the enrolment holds only the numbers, the student’s name is stored once, in the students table. To see names beside enrolments you ask the database to follow the link, which you will learn to do with a join in a later module.'],
            ['h', 'Referential integrity'],
            ['p', 'A link is only useful if it leads somewhere. **Referential integrity** is the rule that every foreign key value must match an existing primary key value, or be NULL if the design allows it. The database enforces this for you when you declare the foreign key, and it prevents several kinds of damage.'],
            ['ul', [
                'It refuses to insert an enrolment for a student_id that does not exist.',
                'It refuses to delete a student who still has enrolments, or deletes those enrolments too if you have chosen a cascading rule.',
                'It refuses to change a primary key value that other rows depend on.',
            ]],
            ['p', 'Without these checks the database slowly fills with **orphan** rows that point to nothing, and reports begin to show mysterious blanks.'],
            ['h', 'Kinds of relationships'],
            ['h', 'One-to-many'],
            ['p', 'The most common relationship. One program has many students, but each student belongs to one program. The foreign key goes on the “many” side: the students table has a `program_id` column.'],
            ['h', 'Many-to-many'],
            ['p', 'A student takes many courses, and a course has many students. Neither table can hold the foreign key, because it would need to hold several values. The solution is a third table, called a **junction table**, with one row for each pairing. The enrolments table is exactly this: each row says “this student is in this course in this term”, and it holds two foreign keys.'],
            ['h', 'One-to-one'],
            ['p', 'Less common. Each row in one table matches at most one row in the other, for example a user and their detailed profile. It is often used to separate rarely used or private columns from the main table.'],
            ['h', 'Worked example: linking courses and instructors'],
            ['p', 'A department wants to record which instructor teaches each course. One instructor can teach several courses, but each course has exactly one instructor.'],
            ['ol', [
                'Identify the relationship: one instructor to many courses, so it is one-to-many.',
                'Give each table a primary key: instructors(instructor_id) and courses(course_id).',
                'Place the foreign key on the many side: add `instructor_id` to the courses table.',
                'Declare it as a foreign key referring to instructors(instructor_id).',
                'Test it: inserting a course with instructor_id 9999 must fail if no such instructor exists.',
            ]],
            ['h', 'Choosing between a natural key and a surrogate key'],
            ['p', 'A **natural key** uses real data that is already unique, such as a course code like IT201. A **surrogate key** is an artificial value created only to identify the row. Natural keys feel convenient but can change or turn out not to be unique after all; a department may rename its codes. Surrogate keys never change, which is why they are the usual choice, sometimes with a unique constraint on the natural value so that duplicates are still prevented.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Using a name or email as the primary key**, which later changes or repeats.',
                '**Storing a list of ids in one column** instead of creating a junction table.',
                '**Forgetting to declare the foreign key**, so the database cannot protect the link.',
                '**Putting the foreign key on the wrong side** of a one-to-many relationship.',
                '**Deleting a parent row first**, then wondering why the child rows now point nowhere.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Primary key**: a unique, non-null identifier for each row.',
                '**Foreign key**: a column that refers to a primary key in another table.',
                '**Referential integrity**: the rule that foreign keys must refer to real rows.',
                '**Junction table**: a table that connects two tables in a many-to-many relationship.',
                '**Surrogate key**: an artificial identifier with no meaning of its own.',
                '**Orphan row**: a row whose foreign key points to nothing.',
            ]],
            ['h', 'Summary'],
            ['p', 'Primary keys give every row a stable identity, and foreign keys use those identities to connect tables without copying data. The database enforces referential integrity so links always lead to real rows. One-to-many relationships put the foreign key on the many side, and many-to-many relationships need a junction table. Good keys are the backbone of a database that stays correct as it grows.'],
        ];
    }
}
