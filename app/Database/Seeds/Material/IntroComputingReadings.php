<?php

declare(strict_types=1);

namespace App\Database\Seeds\Material;

/**
 * IT101 Introduction to Computing: the opening readings.
 */
final class IntroComputingReadings
{
    /** @return array<string, list<array{0: string, 1: string|list<string>}>> */
    public static function all(): array
    {
        return [
            'Welcome to Introduction to Computing' => self::welcome(),
            'Hardware and Software' => self::hardwareAndSoftware(),
            'How Computers Represent Data' => self::representingData(),
            'Welcome to Section B' => self::sectionB(),
        ];
    }

    private static function welcome(): array
    {
        return [
            ['h', 'Welcome to Introduction to Computing'],
            ['p', 'Almost everything you do today passes through a computer: the message you send a classmate, the grade you look up, the route a jeepney driver checks on a phone, the receipt printed at a store. This course explains what is happening underneath. You will learn how a computer is built, how it stores and processes information, how a program tells it what to do, and how large amounts of data are kept organised in a database. You do not need any earlier experience. You only need curiosity and the willingness to try things and make mistakes.'],
            ['h', 'What you will be able to do'],
            ['p', 'By the end of the course you should be able to explain the main parts of a computer and what each one does, describe how numbers, text and images are stored as bits, read and write short programs that use variables, decisions and loops, and design and query a simple database. These are the foundations that every later course in the program builds on.'],
            ['ul', [
                '**Explain** how hardware and software work together to run a program.',
                '**Convert** small numbers between decimal and binary and read a simple character encoding.',
                '**Write** short programs that store values, make decisions and repeat actions.',
                '**Design** a small table, choose a key, and retrieve rows with a SELECT query.',
                '**Work** carefully and safely with files, passwords and other people’s data.',
            ]],
            ['h', 'How the course is organised'],
            ['p', 'The course is divided into **modules**. Each module contains **learning units**, and each unit holds the items you work through: readings like this one, videos, downloadable files, quizzes and assignments. Items are arranged in a deliberate order. Some are locked until you finish the one before them, because later items assume you have met the earlier ideas. You can always return to an item you have already completed, and your progress is saved as you go.'],
            ['p', 'A typical unit follows the same rhythm. You begin with a reading that introduces the idea in plain language. A handout or video then shows it in a different form, often with a diagram or a worked example. A short quiz checks that the idea has stuck, and an assignment asks you to use it on a small problem of your own. Each step is small on purpose. Most people learn better by meeting an idea three times in three forms than by hearing it once for a long time.'],
            ['h', 'How to read a lesson'],
            ['p', 'A reading is not a story to skim from top to bottom. Read it twice. On the first pass, go through at normal speed and notice the headings, the words in bold and the examples. On the second pass, slow down and stop at every worked example. Cover the answer and try the step yourself before you read what comes next. Where the reading says “Common mistakes”, take that seriously: those are the errors that most beginners make, and knowing them in advance saves hours.'],
            ['ol', [
                'Skim the headings and the objectives to see where the reading is going.',
                'Read the whole lesson once without stopping to look things up.',
                'Return to each worked example and solve it yourself before reading the solution.',
                'Write two or three sentences in your own words summarising what you learned.',
                'Make a short list of anything that is still unclear and bring it to class or the discussion board.',
            ]],
            ['h', 'Assessments and grading'],
            ['p', 'Your grade is made of several parts. Quizzes check your understanding of each unit and are usually allowed two attempts, with your best score counted. Assignments are larger tasks that you submit before a due date; they are graded with a rubric so you can see exactly what earns each point. A midterm and a final examination bring the units together. The exact weights are listed in the syllabus, and the gradebook always shows how each piece contributes to your standing so there are no surprises at the end of the term.'],
            ['p', 'Late work is penalised according to the syllabus, but it is always better to submit something than nothing. If a real problem keeps you from a deadline, such as illness or a family emergency, tell your instructor before the due date whenever possible. Instructors can only help with problems they hear about.'],
            ['h', 'Academic honesty'],
            ['p', 'Learning to compute means building your own understanding, and that cannot be borrowed. You are encouraged to discuss ideas with classmates, to read documentation and to ask for help. You are not allowed to submit someone else’s work as your own, to copy a program you do not understand, or to share your answers on a quiz. When you use an idea or a snippet from a source, say where it came from. If you are ever unsure whether something is allowed, ask before you do it, not after.'],
            ['h', 'Working with your own computer'],
            ['p', 'You will be doing hands-on work, so you need a place to practise. A laptop or desktop is best, but a computer lab on campus will do. Keep your files organised in folders with clear names, back them up regularly, and never leave your account signed in on a shared machine. Choose a strong password and do not reuse it on other sites. These small habits protect your work and your privacy, and they are skills this course expects you to build alongside the technical ones.'],
            ['h', 'Common mistakes to avoid'],
            ['ul', [
                '**Waiting until the night before** a deadline. Programming problems often take longer than expected and are easier to solve with a rested mind.',
                '**Reading without doing.** You learn a programming idea by using it, not by recognising it on a page.',
                '**Copying code you do not understand.** It may run, but you will be stuck the moment the problem changes slightly.',
                '**Hiding confusion.** Every question you ask early saves you from a bigger gap later.',
            ]],
            ['h', 'Summary'],
            ['p', 'This course moves from the machine to the program to the data. Work through each item in order, read actively, practise what you read, and ask for help early. In the next lessons you will open up the computer and meet its parts, then learn how every piece of information inside it is reduced to ones and zeros.'],
        ];
    }

    private static function hardwareAndSoftware(): array
    {
        return [
            ['h', 'Hardware and Software'],
            ['p', 'A computer is a machine that follows instructions. To understand it, you need to separate two ideas that people often mix together. **Hardware** is everything you can physically touch: the processor, the memory chips, the storage drive, the screen and the cables. **Software** is the set of instructions that tells the hardware what to do. A computer without software is a very expensive paperweight, and software without hardware is only text with nothing to run it.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Name the main hardware components and state what each one does.',
                'Explain the difference between input, output, processing and storage.',
                'Distinguish system software from application software.',
                'Describe, step by step, what happens when you open a program.',
            ]],
            ['h', 'The main parts of a computer'],
            ['p', 'Inside almost every computer, from a phone to a server, you will find the same handful of parts doing the same handful of jobs.'],
            ['h', 'The processor (CPU)'],
            ['p', 'The **central processing unit**, or **CPU**, is the part that actually carries out instructions. It reads one instruction at a time, performs it, and moves to the next. It does this billions of times every second. A CPU is described by its **clock speed**, measured in gigahertz, and by the number of **cores** it has. Each core can follow its own stream of instructions, so a processor with several cores can work on several tasks at once. A faster clock and more cores generally mean more work done per second, but a poorly written program will still be slow on a powerful CPU.'],
            ['h', 'Memory (RAM)'],
            ['p', '**Random access memory**, or **RAM**, holds the data and instructions the computer is working on right now. It is very fast, which is why the CPU uses it as its workspace, but it is **volatile**: when the power is switched off, everything in RAM disappears. This is why unsaved work is lost in a power failure. More RAM lets you keep more programs and larger files open at the same time without the computer slowing down.'],
            ['h', 'Storage'],
            ['p', 'Storage keeps your files when the power is off. A **hard disk drive** stores data magnetically on spinning platters and is large and inexpensive. A **solid-state drive** stores data in flash memory with no moving parts, which makes it faster and more resistant to knocks. Storage is much slower than RAM, which is why a program is copied from storage into RAM before it runs. A useful comparison is a desk and a filing cabinet: RAM is the desk where you spread out what you are using, and storage is the cabinet where everything is kept.'],
            ['h', 'Input and output devices'],
            ['p', 'An **input device** sends data into the computer; an **output device** sends results out. The keyboard, mouse, microphone, camera and touchscreen are inputs. The monitor, speakers and printer are outputs. A touchscreen is both: it shows you information and also takes your touch. A network card is also both, because it sends data out and brings data in.'],
            ['h', 'The motherboard and the power supply'],
            ['p', 'The **motherboard** is the main circuit board that connects all the other parts and lets them talk to one another. The **power supply** converts the electricity from the wall into the lower voltages the components need. You will rarely think about either of them until one fails, but nothing works without them.'],
            ['h', 'The four jobs of a computer'],
            ['p', 'Every task a computer performs can be described with four verbs.'],
            ['ol', [
                '**Input**: data comes in, such as a key you press or a file you open.',
                '**Processing**: the CPU works on that data by following instructions.',
                '**Storage**: results are kept, temporarily in RAM or permanently on a drive.',
                '**Output**: results are shown or sent out, such as text on the screen or a printed page.',
            ]],
            ['h', 'Software'],
            ['p', 'Software is divided into two broad families. **System software** manages the hardware and provides a platform for other programs. The most important example is the **operating system**, such as Windows, macOS, Linux or Android. The operating system decides which program uses the CPU and for how long, hands out memory, manages files, and talks to devices through small programs called **drivers**. **Application software** helps you do a task of your own: a web browser, a word processor, a game, a spreadsheet.'],
            ['p', 'There is a third category worth knowing about: **firmware**. Firmware is software stored permanently on a chip inside a device, such as the program that starts a computer before the operating system loads, or the program inside a router or a washing machine.'],
            ['h', 'What happens when you open a program'],
            ['p', 'Suppose you double-click the icon of a word processor. Several things happen in quick succession. The operating system finds the program’s file on the storage drive. It copies the instructions into RAM, because the CPU can only work quickly with what is in memory. It gives the program a share of the CPU’s time and a region of memory of its own. The CPU begins carrying out the program’s instructions, which draw a window on the screen and wait for input. When you type, the keyboard sends signals, the operating system passes them to the program, and the program updates what is displayed. When you save, the program asks the operating system to write the data from RAM to storage.'],
            ['h', 'A worked example'],
            ['p', 'Imagine you are adding two numbers in a calculator program. Name the hardware and software involved at each step.'],
            ['ol', [
                'You press the keys. The **keyboard** (input hardware) sends signals to the computer.',
                'The **operating system** receives the signals and passes them to the calculator, which is **application software**.',
                'The calculator’s instructions and the two numbers sit in **RAM** while they are being used.',
                'The **CPU** carries out the addition instruction and produces the result.',
                'The calculator hands the result to the operating system, which displays it on the **monitor** (output hardware).',
            ]],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Confusing memory and storage.** Both hold data, but RAM is fast and temporary while storage is slower and permanent.',
                '**Saying the “hard drive” when you mean the whole computer.** The drive is only one component.',
                '**Thinking a faster CPU always means a faster computer.** Memory, storage speed and the quality of the software matter as well.',
                '**Treating the operating system as just another application.** It is the layer that every application depends on.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**CPU**: the processor that carries out instructions.',
                '**RAM**: fast, temporary working memory that is lost when power is off.',
                '**Storage**: slower, permanent memory for files.',
                '**Operating system**: system software that manages hardware and runs applications.',
                '**Driver**: a small program that lets the operating system use a device.',
                '**Firmware**: software stored permanently on a chip inside a device.',
            ]],
            ['h', 'Summary'],
            ['p', 'A computer takes input, processes it with the CPU using data held in RAM, stores results on a drive, and produces output. Hardware is the physical machine; software is the instructions that direct it, and the operating system sits between the two. With this picture in mind, the next lesson looks at how all of that information is actually represented inside the machine.'],
        ];
    }

    private static function representingData(): array
    {
        return [
            ['h', 'How Computers Represent Data'],
            ['p', 'A computer has no idea what a photograph, a song or a sentence is. Inside the machine there are only tiny switches, each of which is either on or off. Everything you see on a screen, from your name to a video, has been turned into a long pattern of those two states. This lesson explains how that is possible and gives you the skills to read and write simple patterns yourself.'],
            ['h', 'Learning objectives'],
            ['ul', [
                'Explain why computers use the binary number system.',
                'Convert whole numbers between decimal and binary.',
                'Describe how text is stored using a character encoding.',
                'Explain, at a basic level, how images and sound are stored.',
                'Use the units bit, byte, kilobyte, megabyte and gigabyte correctly.',
            ]],
            ['h', 'Bits and bytes'],
            ['p', 'A **bit** is the smallest unit of information, a single binary digit that is either **0** or **1**. One bit by itself can answer a yes-or-no question. To store anything more interesting, bits are grouped. A group of eight bits is a **byte**, and a byte can hold 256 different patterns, from 00000000 to 11111111. Storage sizes are measured in multiples of bytes.'],
            ['p', 'Computers use two states because a circuit that only has to tell “high voltage” from “low voltage” is simple, cheap and very reliable. Distinguishing ten different voltage levels, as a decimal system would need, would be far more error-prone.'],
            ['h', 'Counting in binary'],
            ['p', 'You already know a **place-value** system: the decimal system. In the number 347, the 7 is worth 7 ones, the 4 is worth 4 tens and the 3 is worth 3 hundreds. Each place is worth ten times the place to its right. Binary works in exactly the same way, except that each place is worth **two** times the place to its right. The places, from right to left, are worth 1, 2, 4, 8, 16, 32, 64, 128 and so on.'],
            ['p', 'Take the binary number 1011. From the right, the places are worth 1, 2, 4 and 8. The digits are 1, 1, 0 and 1, so the value is 1 + 2 + 0 + 8 = 11. The number 1011 in binary is the number eleven in decimal.'],
            ['h', 'Worked example: binary to decimal'],
            ['p', 'Convert 110101 to decimal.'],
            ['ol', [
                'Write the place values from the right: 1, 2, 4, 8, 16, 32.',
                'Write the digits under them: 1, 0, 1, 0, 1, 1 (reading right to left).',
                'Add the place values wherever the digit is 1: 1 + 4 + 16 + 32.',
                'The total is 53, so 110101 in binary is 53 in decimal.',
            ]],
            ['h', 'Worked example: decimal to binary'],
            ['p', 'Convert 37 to binary. A reliable method is repeated division by 2, keeping the remainders.'],
            ['ol', [
                '37 divided by 2 is 18 remainder 1.',
                '18 divided by 2 is 9 remainder 0.',
                '9 divided by 2 is 4 remainder 1.',
                '4 divided by 2 is 2 remainder 0.',
                '2 divided by 2 is 1 remainder 0.',
                '1 divided by 2 is 0 remainder 1.',
                'Read the remainders from the last to the first: 100101. So 37 is 100101 in binary.',
            ]],
            ['p', 'Check the answer by converting back: the places 32, 4 and 1 are on, and 32 + 4 + 1 = 37. Always check your work this way; it takes seconds and catches most slips.'],
            ['h', 'How many values fit in a given number of bits?'],
            ['p', 'Each extra bit doubles the number of patterns. With 1 bit there are 2 patterns, with 2 bits 4, with 3 bits 8, and with 8 bits 256. In general, **n** bits can represent 2 to the power of n different values. That is why an 8-bit byte can hold the whole numbers from 0 to 255, and why a 16-bit value can hold 65,536 different values.'],
            ['h', 'Storing text'],
            ['p', 'Text is stored by agreeing that each character corresponds to a number. The number is then stored in binary. The agreement is called a **character encoding**. The classic example is **ASCII**, which uses 7 bits to represent 128 characters: the English letters in upper and lower case, the digits, punctuation and some control codes. In ASCII the capital letter A is the number 65, B is 66, and a lowercase a is 97. The word “Hi” is therefore stored as the numbers 72 and 105, which are 01001000 and 01101001 in binary.'],
            ['p', 'ASCII cannot hold the characters of most of the world’s languages. The modern solution is **Unicode**, a single standard that assigns a number to every character in nearly every writing system, as well as symbols and emoji. The most common way of storing Unicode is **UTF-8**, which uses one byte for the familiar English characters and more bytes for others. This is why a message in Filipino with accented letters, or in Japanese, can travel safely between computers that agree to use the same encoding.'],
            ['h', 'Storing images'],
            ['p', 'A digital image is a grid of tiny dots called **pixels**. Each pixel stores a colour, and the colour is itself a number. A common method uses three numbers per pixel, each from 0 to 255, for the amounts of red, green and blue light. This is called **RGB**. Pure red is (255, 0, 0), white is (255, 255, 255) and black is (0, 0, 0). Because each of the three values takes one byte, one pixel takes three bytes, and an image of 1,000 by 1,000 pixels would take about three million bytes before any compression is applied.'],
            ['p', 'The more pixels there are, the more detail the image can show. This is the **resolution**. Higher resolution means a larger file, which is why photographs are usually **compressed**, using clever tricks to store the same picture in less space. Compression is **lossless** if the original can be recovered exactly, as with PNG, and **lossy** if some detail is thrown away to save space, as with JPEG.'],
            ['h', 'Storing sound'],
            ['p', 'Sound is a continuous wave, but a computer can only keep a list of numbers. To store sound, the wave is **sampled**: its height is measured many times per second and each measurement is stored as a number. A CD samples 44,100 times per second. Playing the numbers back in order through a speaker reconstructs the wave. More samples per second and more bits per sample give a more faithful recording and a larger file.'],
            ['h', 'Units of storage'],
            ['ul', [
                '**Bit**: one binary digit, 0 or 1.',
                '**Byte**: 8 bits, enough for 256 different values.',
                '**Kilobyte (KB)**: about 1,000 bytes, a short paragraph of plain text.',
                '**Megabyte (MB)**: about 1,000 KB, a photograph or a minute of compressed music.',
                '**Gigabyte (GB)**: about 1,000 MB, a feature film or a large program.',
                '**Terabyte (TB)**: about 1,000 GB, the size of a large hard drive.',
            ]],
            ['p', 'Eight bits make one byte. One kilobyte is about one thousand bytes, one megabyte about one thousand kilobytes, one gigabyte about one thousand megabytes, and one terabyte about one thousand gigabytes. A page of plain text takes a few kilobytes, a photograph a few megabytes, a film several gigabytes. Network speeds are usually quoted in bits per second while file sizes are quoted in bytes, so a connection of 80 megabits per second moves about 10 megabytes per second.'],
            ['h', 'Common mistakes'],
            ['ul', [
                '**Reading binary from the wrong end.** The rightmost digit is worth 1; the leftmost is worth the most.',
                '**Forgetting that the places double**, not increase by ten. After 8 comes 16, not 10.',
                '**Mixing up bits and bytes** when comparing speeds and file sizes.',
                '**Assuming every character is one byte.** This is true for basic English text but not for all of Unicode.',
            ]],
            ['h', 'Key terms'],
            ['ul', [
                '**Bit**: a single binary digit, 0 or 1.',
                '**Byte**: eight bits.',
                '**Binary**: the base-2 number system.',
                '**Character encoding**: an agreement that maps characters to numbers (ASCII, Unicode, UTF-8).',
                '**Pixel**: one dot of a digital image.',
                '**Sampling**: measuring a sound wave at regular intervals to store it as numbers.',
            ]],
            ['h', 'Summary'],
            ['p', 'Every kind of data in a computer is reduced to patterns of bits. Numbers use the binary place-value system, text uses a character encoding such as Unicode, images are grids of numeric pixels, and sound is a list of samples. The next module builds on this idea by showing how a program uses stored values to make decisions.'],
        ];
    }

    private static function sectionB(): array
    {
        return [
            ['h', 'Welcome to Section B'],
            ['p', 'Section B follows the same course as Section A, Introduction to Computing, and uses the same readings, quizzes and assignments. This short guide explains how your section works so that you can settle in quickly. Everything described here applies to this section only; ask your instructor if something is not clear.'],
            ['h', 'What is the same as Section A'],
            ['p', 'The learning outcomes, the topics and the order of the modules are identical. You will study the parts of a computer, how data is represented, and the basics of programming and databases. The assessments follow the same structure, and the grading scheme is the same, so a quiz that is worth five percent in Section A is worth five percent here.'],
            ['h', 'What is different'],
            ['ul', [
                'Your class meets at its own scheduled time and room; check the schedule page for the current details.',
                'Due dates may differ by a few days from Section A, because they follow your meeting days. Always use the dates shown on your own assignments.',
                'Your instructor may add announcements or practice exercises specific to this section.',
            ]],
            ['h', 'How to get started'],
            ['ol', [
                'Open the first module and read the course welcome reading from beginning to end.',
                'Watch the orientation video and open the syllabus link.',
                'Take the pre-test. It is not meant to catch you out; it shows you and your instructor where you are starting from.',
                'Introduce yourself in the class discussion if one is open.',
                'Plan a weekly study routine and put your due dates in a calendar.',
            ]],
            ['h', 'A suggested weekly routine'],
            ['p', 'Students who do a little every few days learn far more than students who do everything at once. A routine that works for most people is to read the new lesson early in the week while it is fresh from class, to do the handout or video and attempt the quiz in the middle of the week, and to start the assignment as soon as it is released rather than the night before it is due. Each session can be short: forty-five focused minutes is better than three tired hours.'],
            ['ul', [
                '**Monday or Tuesday**: read the lesson twice and write a short summary in your own words.',
                '**Wednesday**: open the handout, work through the examples and attempt the quiz.',
                '**Thursday**: begin the assignment and note any questions to ask in class.',
                '**Friday or the weekend**: finish and check the assignment, then review the week’s key terms.',
            ]],
            ['h', 'What to bring to each class'],
            ['p', 'Come to class having read the lesson, even if you did not understand all of it. Bring your questions, a notebook for sketches and notes, and your device if the lesson includes practice. Work you have already attempted is much easier to get help with than a blank page: a teacher can see exactly where your thinking went off course and correct it in a minute.'],
            ['h', 'Working with classmates'],
            ['p', 'Study groups are one of the best ways to learn this material. Explaining an idea to someone else shows you quickly whether you really understand it. Good group habits are to meet at a regular time, to let each person attempt a problem alone before comparing answers, and to explain reasoning rather than only handing over results. The boundary to remember is that every submitted assignment must be your own work, even when you discussed the ideas together.'],
            ['h', 'Getting help'],
            ['p', 'There are several ways to get help, and you should use them early rather than late. Ask in class when something is unclear, because there is usually someone else with the same question. Post in the course discussion so that the answer helps everyone. Visit your instructor during consultation hours for questions about your own work. If the problem is technical, such as not being able to open a file, say exactly what you tried and what message you saw; that detail makes a problem much faster to fix.'],
            ['h', 'Your responsibilities'],
            ['p', 'You are responsible for keeping up with the readings, submitting work on time and doing your own work. You are also responsible for treating classmates with respect in discussions and in group work. Disagreeing with an idea is welcome; making someone feel unwelcome is not.'],
            ['h', 'Summary'],
            ['p', 'Section B covers the same material as Section A at its own pace and schedule. Read the welcome reading, take the pre-test, set a routine and ask for help early. Welcome, and good luck.'],
        ];
    }
}
