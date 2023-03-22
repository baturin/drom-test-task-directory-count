<?php
/**
 * CLI utility to count sum of the first numbers in 'count' files inside of a directory.
 * 
 * Usage: php count.php <directory>
 *      <directory> - path to a directory to find 'count' files
 * 
 * Requirements: PHP >= 8.1
 * 
 * File lookup details:
 * 1. We look for 'count' files recursively in the whole directory tree.
 * 2. If we can't read parent directory - error is emitted.
 * 3. If we can't read subdirectory of any depth - it is silently skipped.
 * 4. Symbolic links are not followed to avoid infinite recursion.
 * 
 * File reading details:
 * 1. If we can't open the file (e.g. due to permission problems) - it is silently skipped.
 * 2. If the file starts with anything that is not a number - it is silently skipped.
 * 3. Everything after the number is silently skipped.
 * 4. Integer numbers are supported ('%d' of printf/scanf format).
 * 5. Numbers above PHP_INT_MAX are considered as PHP_INT_MAX, 
 *    numbers below PHP_INT_MIN are considered as PHP_INT_MIN.
 * 
 * Summing up details:
 * 1. In case of integer overflow, number could get converted to float.
 * 2. If no files are found, 0 is returned.
 */

class CountCli
{
    public function __construct(
        private CountFileFinder $countFileFinder,
        private CountFileReader $countFileReader,
        private string $countFileName
    ) {
    }

    public function run(array $argv): void
    {
        if (count($argv) !== 2) {
            $this->exitWithUsage($argv);
        }

        $dirPath = $argv[1];

        try {
            $sum = $this->countFilesSum($dirPath);
        } catch (UserFatalError $e) {
            $this->exitWithFatalError($e->getMessage());
        }

        echo "Sum of all '{$this->countFileName}' files: $sum" . PHP_EOL;
    }

    private function countFilesSum(string $rootDir): int|float
    {
        $sum = 0;

        foreach ($this->countFileFinder->iterateCountFiles($rootDir) as $countFilePath) {
            $numberFromFile = $this->countFileReader->readNumber($countFilePath);
            if ($numberFromFile !== null) {
                $sum += $numberFromFile;
            }
        }
        
        return $sum;
    }

    private function exitWithFatalError(string $errorMsg): never
    {
        echo $errorMsg . PHP_EOL;
        exit(1);
    }

    private function exitWithUsage(array $argv): never
    {
        echo "Usage: php {$argv[0]} <directory>" . PHP_EOL;
        echo "  <directory> - path to a directory to find '{$this->countFileName}' files" . PHP_EOL;
        exit(1);
    }
}

class UserFatalError extends \Exception
{
}

class CountFileFinder
{
    public function __construct(
        private string $countFileName
    ) {
    }

    public function iterateCountFiles(string $dirPath)
    {
        try {
            $dir = new RecursiveDirectoryIterator($dirPath);
        } catch (\UnexpectedValueException $e) {
            throw new UserFatalError(
                "Can not open directory with path '$dirPath': " . 
                "check if it exists and has correct permissions"
            );
        } catch (\ValueError $e) {
            // e.g. empty directory name
            throw new UserFatalError(
                "Invalid directory path '$dirPath' is specified"
            );
        }

        $dirIterator = new RecursiveIteratorIterator(
            $dir,
            // Silently skip all "bad" subdirectories, 
            // e.g. when we don't have read permission
            flags: RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($dirIterator as $item) {
            if (
                $item->isFile() &&
                $item->getFilename() === $this->countFileName
            ) {
                yield $item->getPathname();
            }
        }
    }
}

class CountFileReader
{
    public function readNumber(string $filePath): ?int
    {
        // silently skip all read errors, 
        // e.g. when there are no permissions to read file
        $contents = @\file_get_contents(
            $filePath, 
            length: max(strlen(PHP_INT_MIN), strlen(PHP_INT_MAX))
        );
        if ($contents === false) {
            return null;
        }
        // null is set to $number if $contents does not match pattern
        [$number] = \sscanf($contents, '%d');
        return $number;
    }
}

$countFileName = 'count';
$cli = new CountCli(
    new CountFileFinder($countFileName),
    new CountFileReader(),
    $countFileName
);
$cli->run($argv);
