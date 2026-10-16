<?php

declare(strict_types=1);

namespace App\Database\Seeds\Material;

/**
 * Programming readings (IT101 Programming Fundamentals and IT102 Computer
 * Programming 1). Examples use pseudocode and PHP.
 */
final class ProgrammingReadings
{
    /** @return array<string, list<array{0: string, 1: string|list<string>}>> */
    public static function all(): array
    {
        return [
            'What Is a Variable?' => self::variable(),
            'Making Decisions with If Statements' => self::decisions(),
            'Repeating with Loops' => self::loops(),
            'Your First PHP Script' => self::firstPhpScript(),
        ];
    }

    private static function variable(): array
    {
        return [
            ['h', 'What Is a Variable?'],
            ['p', 'Nearly every program needs to remember something: a player’s score, a student’s name, the total of a shopping cart. A **variable** is how a program remembers. It is a named place in the computer’s memory that holds a value, and the value can change while the program runs. If you have ever used a calculator’s memory button, you have used the idea already. In this lesson you will learn how to create variables, give them good names, choose the right kind of value for them, and use them in simple calculations.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Explain what a variable is and why programs need them.',
                'Declare a variable and assign and change its value.',
                'Choose clear, valid names for variables.',
                'Recognise the basic data types: integer, floating-point number, string and boolean.',
                'Use arithmetic and string operations with variables.',
            ]],
            ['h', 'A variable is a labelled box'],
            ['p', 'A useful picture is a box with a label on the front. The label is the variable’s **name**; what is inside the box is its **value**. When you write `score = 10`, you are putting the value 10 into the box labelled score. When you later write `score = score + 5`, the program looks inside the box, finds 10, adds 5, and puts the new value, 15, back into the same box. The old value is gone. The name stays the same, but the contents change. That is what “variable” means: the value can vary.'],
            ['p', 'It is important to read the equals sign correctly. In mathematics, `x = x + 1` is impossible. In a program it is perfectly sensible, because **= is not a statement of equality but an instruction to assign**. Read it as “let the variable on the left become the value on the right”.'],
            ['h', 'Declaring and assigning'],
            ['p', 'Different languages have different rules for creating a variable. Some require you to announce its type first; others create the variable the first time you give it a value. In PHP, all variable names begin with a dollar sign and a variable comes into existence when you assign to it.'],
            ['code', '$name = "Maria";\n$age = 19;\n$gpa = 3.75;\n$isEnrolled = true;'],
            ['p', 'Each line above creates a variable and stores a value in it. You can then use the variable anywhere you would have used the value, and you can assign a new value at any time.'],
            ['h', 'Naming variables'],
            ['p', 'A name is a message to the next person who reads your program, and that person is often you in three weeks. Good names are short, specific and describe what the variable holds.'],
            ['ul', [
                '**Use meaningful words.** `totalPrice` tells a reader far more than `t` or `x`.',
                '**Follow one style consistently.** Common styles are `camelCase` (`totalPrice`) and `snake_case` (`total_price`).',
                '**Start with a letter or underscore, never a digit.** `2ndScore` is not allowed; `secondScore` is.',
                '**Do not use spaces or most punctuation.** Use underscores or capital letters to separate words.',
                '**Remember that case matters in many languages.** `Score` and `score` can be different variables.',
            ]],
            ['h', 'Data types'],
            ['p', 'The value inside a variable has a **type**, which decides what you can do with it. You can add two numbers, but adding a number to a name does not make sense. The four types you will meet most often are the following.'],
            ['ul', [
                '**Integer**: a whole number with no fractional part, such as 7, 0 or -42. Used for counts and positions.',
                '**Floating-point number** (float): a number with a decimal part, such as 3.14 or -0.5. Used for measurements and money in simple examples.',
                '**String**: a sequence of characters in quotation marks, such as "Maria" or "Room 204". Used for names, messages and any text.',
                '**Boolean**: exactly one of two values, true or false. Used for answers to yes-or-no questions.',
            ]],
            ['p', 'Be careful with quotation marks. The value 5 is a number, but "5" is a string that happens to contain the character five. Adding 5 and 5 gives 10, while joining "5" and "5" gives "55". Many beginners’ bugs come from mixing the two.'],
            ['h', 'Doing things with variables'],
            ['p', 'Variables become useful when you combine them. With numbers you can use the arithmetic operators: `+` to add, `-` to subtract, `*` to multiply, `/` to divide and `%` to give the remainder of a division. With strings, joining two pieces of text together is called **concatenation**, and in PHP it uses a full stop.'],
            ['code', '$price = 250;\n$quantity = 3;\n$total = $price * $quantity;      // 750\n\n$first = "Juan";\n$last = "dela Cruz";\n$full = $first . " " . $last;   // Juan dela Cruz'],
            ['h', 'Worked example: a simple receipt'],
            ['p', 'A canteen sells rice meals at 45 pesos each. A customer buys four meals and pays with a 200-peso bill. Write the steps a program would take to find the change.'],
            ['ol', [
                'Store the price: `$price = 45;`',
                'Store the quantity: `$quantity = 4;`',
                'Calculate the cost: `$cost = $price * $quantity;` which gives 180.',
                'Store the payment: `$paid = 200;`',
                'Calculate the change: `$change = $paid - $cost;` which gives 20.',
                'Show the result: the customer receives 20 pesos in change.',
            ]],
            ['p', 'Notice that each step does one small thing, and every intermediate value has its own name. If the price changes tomorrow, you change one line and every later calculation follows automatically. That is the practical value of variables.'],
            ['h', 'Constants'],
            ['p', 'Sometimes a value should never change while the program runs, such as the number of days in a week or the rate of a sales tax. A **constant** is a named value that cannot be reassigned. Using a constant instead of typing the number everywhere makes a program easier to read and safer to change: you update the value in one place.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Using a variable before giving it a value.** The program has no idea what it should contain.',
                '**Misspelling a name.** `totalPrice` and `totalprice` may be treated as different variables, and the program will quietly use an empty value.',
                '**Confusing assignment with equality.** One equals sign assigns; checking equality uses a different operator you will meet in the next lesson.',
                '**Mixing types.** Joining a number and a string, or doing arithmetic on text, may give surprising results.',
                '**Choosing one-letter names** for everything, which makes the program unreadable later.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Variable**: a named place in memory holding a value that can change.',
                '**Assignment**: storing a value in a variable.',
                '**Data type**: the kind of value a variable holds.',
                '**String**: a sequence of characters.',
                '**Boolean**: a value that is true or false.',
                '**Constant**: a named value that does not change.',
                '**Concatenation**: joining strings end to end.',
            ]],
            ['h', 'Summary'],
            ['p', 'A variable is a named place that stores a value the program can read and change. Every value has a type, names should be meaningful, and the equals sign means “assign”. With variables you can store data, calculate with it and build up results step by step. In the next lesson you will use variables to let a program make decisions.'],
        ];
    }

    private static function decisions(): array
    {
        return [
            ['h', 'Making Decisions with If Statements'],
            ['p', 'A program that does exactly the same thing every time is not very useful. Real programs react: they let you in only if the password is correct, they apply a discount only if you spent enough, they show a warning only when a value is out of range. The tool that makes this possible is the **conditional statement**, most commonly the **if statement**. In this lesson you will learn to ask a question inside a program and to choose what happens next depending on the answer.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Write conditions using comparison operators.',
                'Use if, else and else if to choose between actions.',
                'Combine conditions with and, or and not.',
                'Trace through a program by hand to predict its output.',
                'Avoid the most common mistakes with conditions.',
            ]],
            ['h', 'Conditions and booleans'],
            ['p', 'A decision starts with a **condition**, an expression that is either true or false. You met the boolean type in the last lesson; a condition produces a boolean. Conditions are usually made with **comparison operators**.'],
            ['ul', [
                '`==` is equal to',
                '`!=` is not equal to',
                '`<` is less than, and `<=` is less than or equal to',
                '`>` is greater than, and `>=` is greater than or equal to',
            ]],
            ['p', 'Notice that `==` has two equals signs. A single equals sign assigns a value; two equals signs ask whether two values are the same. Writing one where the other is intended is one of the oldest mistakes in programming.'],
            ['h', 'The if statement'],
            ['p', 'The simplest form runs a block of code only when a condition is true. If the condition is false, the block is skipped and the program carries on after it.'],
            ['code', '$score = 82;\n\nif ($score >= 75) {\n    echo "You passed.";\n}'],
            ['p', 'The program compares 82 with 75, finds the condition true, and displays the message. If `$score` were 70, nothing would be displayed.'],
            ['h', 'Adding an else'],
            ['p', 'Often you want to do one thing if the condition is true and something different if it is false. The **else** part supplies the alternative. Exactly one of the two blocks will run.'],
            ['code', 'if ($score >= 75) {\n    echo "You passed.";\n} else {\n    echo "You did not pass. Please review and try again.";\n}'],
            ['h', 'Choosing among several options'],
            ['p', 'When there are more than two outcomes, chain the conditions with **else if**. The program checks them from top to bottom and runs the first block whose condition is true, then skips the rest. Because the order matters, put the most specific or highest threshold first.'],
            ['code', 'if ($score >= 90) {\n    $grade = "A";\n} else if ($score >= 80) {\n    $grade = "B";\n} else if ($score >= 70) {\n    $grade = "C";\n} else {\n    $grade = "F";\n}'],
            ['h', 'Combining conditions'],
            ['p', 'Sometimes one test is not enough. The **logical operators** combine conditions.'],
            ['ul', [
                '**and** (`&&` in many languages): true only when both sides are true.',
                '**or** (`||`): true when at least one side is true.',
                '**not** (`!`): reverses a condition, so true becomes false.',
            ]],
            ['code', '$age = 17;\n$hasPermission = true;\n\nif ($age >= 18 || $hasPermission) {\n    echo "You may join the trip.";\n}'],
            ['p', 'Here the person is 17, so the first test is false, but they have permission, so the second is true, and an or needs only one of them. With `and` the same example would fail.'],
            ['h', 'Worked example: a library fine'],
            ['p', 'A library charges 5 pesos per day for a late book, but never more than 100 pesos, and nothing at all for the first two days after the due date. Decide what the program must check.'],
            ['ol', [
                'Store the number of days late in `$daysLate`.',
                'If `$daysLate` is 2 or less, the fine is 0.',
                'Otherwise, the fine is `$daysLate * 5`.',
                'If the result is greater than 100, set the fine to 100.',
                'Display the fine.',
            ]],
            ['p', 'Test the logic with three values. For 1 day, the fine is 0. For 10 days, the fine is 50. For 40 days, the multiplication gives 200, which is capped at 100. Testing the boundaries, such as exactly 2 days, is the habit that catches most errors.'],
            ['h', 'Tracing a program'],
            ['p', 'A skill that separates confident programmers from anxious ones is the ability to run a program in your head or on paper. Write down each variable and update its value line by line. Suppose `$x = 6` and the program says: if `$x` is greater than 5, set `$y = $x * 2`, else set `$y = $x`. You decide the condition is true, so `$y` becomes 12. When your trace and the real output disagree, you have found a misunderstanding worth fixing.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Using = instead of ==** inside a condition, which assigns a value rather than comparing.',
                '**Checking conditions in the wrong order**, so a broad test hides a more specific one below it.',
                '**Forgetting the boundary**, for example writing `>` when the rule says “at least”, which needs `>=`.',
                '**Comparing a number with a string** and getting an unexpected answer.',
                '**Duplicating code** in several branches when a single calculation before the if would do.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Condition**: an expression that is true or false.',
                '**Comparison operator**: a symbol that compares two values.',
                '**Branch**: one of the paths a program may take.',
                '**Logical operator**: and, or and not.',
                '**Trace**: stepping through a program by hand.',
            ]],
            ['h', 'Summary'],
            ['p', 'An if statement lets a program choose what to do by testing a condition. Use else for the alternative, else if for several options, and the logical operators to combine tests. Always test the boundary values. In the next lesson you will learn how to make a program repeat an action without writing it out again and again.'],
        ];
    }

    private static function loops(): array
    {
        return [
            ['h', 'Repeating with Loops'],
            ['p', 'Computers are excellent at doing the same thing many times without getting bored or making a slip. Suppose you needed to print the numbers from 1 to 100, or add up the marks of forty students, or keep asking for a password until the right one is entered. Writing out each step by hand would be tedious and error-prone. A **loop** lets you write the steps once and have the computer repeat them. This lesson introduces the main kinds of loop and the care that every loop needs.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Explain what a loop is and when to use one.',
                'Write while, for and foreach loops.',
                'Use a counter and an accumulator.',
                'Recognise and avoid infinite loops and off-by-one errors.',
                'Trace a loop by hand.',
            ]],
            ['h', 'The idea of repetition'],
            ['p', 'Every loop has the same three parts: something that **starts** it, a **condition** that decides whether to go around again, and a **body** of steps that are repeated. In between, something inside the loop must change, so that the condition eventually becomes false. If nothing changes, the loop never ends.'],
            ['h', 'The while loop'],
            ['p', 'A **while** loop repeats its body as long as its condition is true. It checks the condition before every pass, so if the condition is false at the start, the body never runs.'],
            ['code', '$count = 1;\n\nwhile ($count <= 5) {\n    echo $count . " ";\n    $count = $count + 1;\n}\n// prints: 1 2 3 4 5'],
            ['p', 'Read it slowly. The variable `$count` starts at 1. The condition asks whether it is at most 5. The body prints the number and then adds 1. After the fifth pass `$count` is 6, the condition is false, and the loop ends.'],
            ['h', 'The for loop'],
            ['p', 'When you know in advance how many times to repeat, a **for** loop packs the start, the condition and the change into a single line, which makes it harder to forget one of them.'],
            ['code', 'for ($i = 1; $i <= 5; $i++) {\n    echo $i . " ";\n}\n// prints: 1 2 3 4 5'],
            ['p', 'The three parts inside the parentheses are the starting value, the test and the update. `$i++` is shorthand for adding 1 to `$i`. The for and while examples above do exactly the same thing.'],
            ['h', 'The foreach loop'],
            ['p', 'Programs often hold a list of values, called an **array**. A **foreach** loop visits each item in the list once, in order, without you having to count.'],
            ['code', '$names = ["Ana", "Ben", "Carla"];\n\nforeach ($names as $name) {\n    echo "Hello, " . $name . "<br>";\n}'],
            ['h', 'Counters and accumulators'],
            ['p', 'Two patterns appear in almost every program that uses loops. A **counter** is a variable that goes up by one each time something happens, such as the number of passing students. An **accumulator** is a variable that builds up a running total by adding each item to it.'],
            ['h', 'Worked example: the average mark'],
            ['p', 'Find the average of the marks 70, 85, 90 and 75.'],
            ['ol', [
                'Set `$total = 0;` and `$count = 0;`.',
                'Put the marks in an array: `$marks = [70, 85, 90, 75];`.',
                'For each mark, add it to `$total` and add 1 to `$count`.',
                'After the loop, `$total` is 320 and `$count` is 4.',
                'Divide: `$average = $total / $count;` which gives 80.',
            ]],
            ['p', 'Starting the total at zero is essential. If it started with some leftover value, every result would be wrong by that amount. Setting up variables correctly before a loop is as important as the loop itself.'],
            ['h', 'Controlling a loop'],
            ['p', 'Two keywords change the normal flow. **break** ends the loop immediately, which is useful when you have found what you were looking for. **continue** skips the rest of the current pass and goes straight to the next one. Use them sparingly; a loop with a clear condition is easier to understand than one full of exits.'],
            ['h', 'Nested loops'],
            ['p', 'A loop can sit inside another loop. The inner loop runs completely for every single pass of the outer loop. This is how a program prints a multiplication table or works through the rows and columns of a grid. If the outer loop runs 5 times and the inner runs 4 times, the innermost body runs 20 times, so nested loops grow quickly.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**The infinite loop.** Forgetting to change the variable in the condition, so the loop never ends. If a program seems frozen, check this first.',
                '**The off-by-one error.** Running one time too many or too few, usually by writing `<` where `<=` is needed or the reverse. Test the first and last passes by hand.',
                '**Forgetting to reset a total** before a second loop, so values from the first pass leak into the second.',
                '**Changing the list while looping over it**, which can skip or repeat items.',
                '**Doing work inside a loop that belongs outside it**, which slows the program down for no reason.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Loop**: a block of steps repeated while a condition holds.',
                '**Iteration**: one pass through a loop.',
                '**Counter**: a variable that counts events.',
                '**Accumulator**: a variable that builds a running total.',
                '**Array**: an ordered list of values.',
                '**Infinite loop**: a loop that never ends.',
            ]],
            ['h', 'Summary'],
            ['p', 'Loops repeat steps so that you write them once. Use while when you repeat until something changes, for when you know the count, and foreach to walk through a list. Set up your counters and accumulators first, make sure something inside the loop moves it toward ending, and test the first and last passes. With variables, decisions and loops you now have the three building blocks of nearly every program.'],
        ];
    }

    private static function firstPhpScript(): array
    {
        return [
            ['h', 'Your First PHP Script'],
            ['p', 'PHP is a programming language that runs on a web server and produces the pages that your browser displays. When you ask for a page written in PHP, the server runs the program first and sends you only the result, not the program itself. In this lesson you will set up a place to run PHP on your own computer, write a small script, run it, read the error messages that appear when you make a mistake, and understand what each line does.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Describe how a PHP page is requested, run and returned.',
                'Create a PHP file in the correct folder and open it through the local server.',
                'Use echo to produce output and variables to store values.',
                'Mix PHP with HTML in the same file.',
                'Read a basic PHP error message and fix the problem.',
            ]],
            ['h', 'How PHP runs'],
            ['p', 'A normal web page is a file that the server sends to your browser unchanged. A PHP page is different. The browser asks the server for the file, the server hands it to the **PHP interpreter**, the interpreter follows the instructions inside it and produces text, and the server sends that text back. This is why you cannot simply double-click a PHP file and see the result: the interpreter must run it first. You need a web server and PHP installed, and the XAMPP package provides both.'],
            ['h', 'Preparing your workspace'],
            ['ol', [
                'Install XAMPP using the installation guide handout, and start the **Apache** module from the XAMPP control panel.',
                'Open the folder named `htdocs` inside the XAMPP folder. This is where the server looks for your pages.',
                'Create a new folder called `myfirst` inside `htdocs`. Keep your work for each exercise in its own folder.',
                'Open your code editor and create a new file named `hello.php` inside that folder.',
            ]],
            ['h', 'Writing the script'],
            ['p', 'Type the following exactly as it appears and save the file.'],
            ['code', '<?php\n\n$name = "Maria";\n$year = 2026;\n\necho "Hello, " . $name . "!";\necho "<br>";\necho "Welcome to the class of " . $year . ".";'],
            ['p', 'Now open your browser and visit `http://localhost/myfirst/hello.php`. You should see two lines of greeting. If you see the code itself or a download prompt, the file was not served through the server; check that Apache is running and that you used the localhost address rather than opening the file from the disk.'],
            ['h', 'Reading the script line by line'],
            ['ul', [
                '`<?php` opens PHP mode. Everything after it is treated as PHP code until the file ends or PHP is closed.',
                '`$name = "Maria";` creates a variable holding a string. Every statement ends with a semicolon.',
                '`$year = 2026;` stores a number.',
                '`echo` sends text to the browser. The full stop joins strings together, so the pieces become one message.',
                '`"<br>"` is HTML, a line break, sent as text for the browser to interpret.',
            ]],
            ['h', 'Mixing PHP and HTML'],
            ['p', 'A PHP file can contain ordinary HTML with small pieces of PHP inside it. Anything outside the PHP tags is sent unchanged. This lets you build a page layout once and let PHP fill in the changing parts.'],
            ['code', '<!DOCTYPE html>\n<html>\n<head><title>Greeting</title></head>\n<body>\n  <h1>Hello, <?php echo $name; ?>!</h1>\n  <p>Today is <?php echo date("l, F j, Y"); ?>.</p>\n</body>\n</html>'],
            ['p', 'The built-in function `date` returns the current date in the format you describe. Each time you reload the page the server runs the code again, so the date is always current.'],
            ['h', 'When something goes wrong'],
            ['p', 'Every programmer sees errors constantly; they are not a sign of failure but a normal part of the work. PHP tells you what it did not understand and on which line. Learn to read the message slowly.'],
            ['ul', [
                '**Parse error: syntax error, unexpected ...** means PHP could not understand the structure of a line. The usual causes are a missing semicolon, a missing closing quote or bracket, or a typing slip. Look at the line mentioned and the one just before it.',
                '**Undefined variable** means you used a variable name that was never assigned, often through a spelling difference.',
                '**Call to undefined function** means the function name is misspelled or does not exist.',
                '**A blank page** often means an error occurred but error display is turned off; check the server’s error log.',
            ]],
            ['h', 'Worked example: a simple price tag'],
            ['p', 'Write a script that shows an item, its price and the price after a 10 percent discount.'],
            ['ol', [
                'Create `$item = "Notebook";` and `$price = 80;`.',
                'Calculate `$discounted = $price - ($price * 0.10);` which gives 72.',
                'Echo the item and both prices on separate lines.',
                'Reload the page. Change the price and reload again to confirm that the discounted value follows.',
            ]],
            ['h', 'Good habits from the start'],
            ['ul', [
                '**Save before you refresh.** The browser can only run what is on the disk.',
                '**Change one thing at a time** so you know which change caused a problem.',
                '**Indent your code** consistently. It makes mistakes easier to see.',
                '**Write a short comment** for anything that is not obvious, using `//`.',
                '**Keep each exercise in its own folder** so files do not get mixed up.',
            ]],
            ['h', 'Common mistakes'],
            ['ul', [
                'Opening the file directly from the disk instead of through `localhost`.',
                'Saving the file with a `.html` extension, so the server never runs the PHP.',
                'Leaving out the dollar sign in a variable name.',
                'Putting the file outside the `htdocs` folder.',
                'Forgetting the semicolon at the end of a statement.',
            ]],
            ['h', 'Summary'],
            ['p', 'A PHP script is run by the server and only its output reaches the browser. You prepared a folder in `htdocs`, wrote a script that stores values and prints them, mixed PHP with HTML and learned to read common errors. Next you will study variables in PHP in more detail through the reference handout.'],
        ];
    }
}
