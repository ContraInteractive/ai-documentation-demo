<?php
// generate_docs.php

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Documentation Generator Script
 *
 * This script traverses a specified directory to find PHP files, parses them to extract classes and methods,
 * generates documentation using Ollama, and compiles the documentation into a Markdown file.
 */

// Define the directory containing your PHP source files
$sourceDir = __DIR__ . '/src'; // Adjust the path as needed

// Define the output documentation file
$outputDoc = __DIR__ . '/DOCUMENTATION.md';

// Initialize the documentation content
$documentation = "# YourPackage Documentation\n\n## Table of Contents\n\n- [Introduction](#introduction)\n- [Installation](#installation)\n- [Usage](#usage)\n- [API Reference](#api-reference)\n\n";

// Function to recursively get all PHP files in a directory
function getPhpFiles($dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    $files = [];
    foreach ($rii as $file) {
        if ($file->isDir()){
            continue;
        }
        if (pathinfo($file->getPathname(), PATHINFO_EXTENSION) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// Initialize PHP Parser
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

// Function to extract classes and their methods from a PHP file
function extractClassesAndMethods($filePath, $parser) {
    try {
        $code = file_get_contents($filePath);
        $ast = $parser->parse($code);

        $traverser = new NodeTraverser();
        $visitor = new class extends NodeVisitorAbstract {
            public $classes = [];
            private $filePath = '';

            public function setFilePath($path) {
                $this->filePath = $path;
            }

            public function enterNode(Node $node) {
                if ($node instanceof Node\Stmt\Class_) {
                    $className = $node->name->name;
                    $methods = [];
                    foreach ($node->getMethods() as $method) {
                        $methodName = $method->name->name;
                        $methodDoc = $method->getDocComment() ? $method->getDocComment()->getText() : '';
                        $methods[] = [
                            'name' => $methodName,
                            'doc' => $methodDoc,
                            'code' => $this->getMethodCode($method),
                        ];
                    }
                    $this->classes[] = [
                        'name' => $className,
                        'doc' => $node->getDocComment() ? $node->getDocComment()->getText() : '',
                        'methods' => $methods,
                    ];
                }
            }

            private function getMethodCode($method) {
                $startLine = $method->getStartLine();
                $endLine = $method->getEndLine();
                if ($this->filePath && file_exists($this->filePath)) {
                    $file = new SplFileObject($this->filePath);
                    $file->seek($startLine - 1); // Zero-based index
                    $code = '';
                    for ($i = $startLine; $i <= $endLine; $i++) {
                        if ($file->eof()) break;
                        $code .= $file->current();
                        $file->next();
                    }
                    return $code;
                }
                return '';
            }
        };

        // Set the current file path for the visitor
        $visitor->setFilePath($filePath);

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->classes;
    } catch (Error $e) {
        echo "Parse error in file $filePath: {$e->getMessage()}\n";
        return [];
    }
}

// Function to generate prompts
function generatePrompt($codeSegment, $segmentType, $name) {
    if ($segmentType === 'class') {
        $prompt = "Generate detailed documentation for the following PHP class named `{$name}`:\n\n```php\n{$codeSegment}\n```\n\nInclude a description of the class's purpose, its properties, and its methods with explanations of their functionalities.";
    } elseif ($segmentType === 'method') {
        $prompt = "Generate detailed documentation for the following PHP method named `{$name}`:\n\n```php\n{$codeSegment}\n```\n\nInclude a description of what the method does, its parameters, return values, and an example usage.";
    } else {
        $prompt = "Generate documentation for the following PHP code:\n\n```php\n{$codeSegment}\n```\n\n";
    }
    return $prompt;
}

// Function to call Ollama and get the response
function callOllama($prompt) {
    // Escape single quotes in the prompt
    $escapedPrompt = escapeshellarg($prompt);

    // Replace <model-name> with your actual model name, e.g., "gpt-4"
    $modelName = "phi4:latest"; // Adjust as needed

    // Ollama CLI command
    $command = "ollama run {$modelName} {$escapedPrompt}";

    // Execute the command and capture the output
    $output = shell_exec($command);

    // Trim and return the output
    return trim($output);
}

// Get all PHP files
$phpFiles = getPhpFiles($sourceDir);

// Collect all classes and methods
$allClasses = [];
foreach ($phpFiles as $file) {
    echo "Processing file: $file\n";
    $classes = extractClassesAndMethods($file, $parser);
    $allClasses = array_merge($allClasses, $classes);
}

// Generate Documentation for Each Class and Method
foreach ($allClasses as $class) {
    $className = $class['name'];
    $classDoc = $class['doc'];
    $classCode = "<?php\n" . "namespace YourPackage;\n\n" . $classDoc . "\nclass {$className} {\n // ... \n}";

    // Generate prompt for class documentation
    $classPrompt = generatePrompt($classCode, 'class', $className);
    echo "Generating documentation for class: $className\n";
    $classDocumentation = callOllama($classPrompt);

    // Append to the documentation
    $documentation .= "## Class `{$className}`\n\n{$classDocumentation}\n\n";

    // Generate Documentation for Each Method
    foreach ($class['methods'] as $method) {
        $methodName = $method['name'];
        $methodDoc = $method['doc'];
        $methodCode = "<?php\n" . $methodDoc . "\npublic function {$methodName}() {\n // ... \n}";

        // Generate prompt for method documentation
        $methodPrompt = generatePrompt($methodCode, 'method', $methodName);
        echo "Generating documentation for method: {$methodName}()\n";
        $methodDocumentation = callOllama($methodPrompt);

        // Append to the documentation
        $documentation .= "### Method `{$methodName}()`\n\n{$methodDocumentation}\n\n";
    }
}

// Optionally, add more sections like Introduction, Installation, Usage, etc.
$introduction = <<<EOD
## Introduction

Welcome to the `YourPackage` documentation. This package provides essential tools and utilities to enhance your Laravel applications.

## Installation

To install the `YourPackage` Composer package, follow these steps:

1. **Require the Package via Composer:**

   Open your terminal, navigate to your Laravel project's root directory, and run the following command:

   ```bash
   composer require yourvendor/yourpackage 
EOD;

$documentation = $introduction . "\n\n" . $documentation;
file_put_contents($outputDoc, $documentation);