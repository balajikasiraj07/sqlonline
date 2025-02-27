<?php
class SQLFormatter {
    private array $keywords;
    private string $indentation = "\t";
    private array $stringMap = [];
    private int $placeholder = 0;
    
    public function __construct() {
        $this->keywords = [
            'MAJOR_KEYWORDS' => [
                'SELECT', 'INSERT', 'UPDATE', 'DELETE',
                'CREATE', 'DROP', 'ALTER', 'TRUNCATE'
            ],
            'CLAUSE_KEYWORDS' => [
                'FROM', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY',
                'LIMIT', 'OFFSET', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN',
                'INNER JOIN', 'OUTER JOIN', 'UNION', 'UNION ALL'
            ],
            'LOGICAL_OPERATORS' => [
                'AND', 'OR', 'NOT', 'IN', 'EXISTS', 'BETWEEN',
                'LIKE', 'IS NULL', 'IS NOT NULL'
            ],
            'FUNCTIONS' => [
                'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE',
                'CONCAT', 'OVER', 'RANK', 'LEAD', 'LAG', 'DATE_SUB'
            ]
        ];
    }

    public function format(string $sql): string {
        // First, ensure there's a space before GROUP BY
        $sql = preg_replace('/(\S)(GROUP\s+BY\b)/i', '$1 $2', trim($sql));
        
        // Normalize other whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Protect strings and special characters
        $sql = $this->protectStrings($sql);
        
        // Apply formatting to get the basic structure
        $sql = $this->formatKeywords($sql);
        $sql = $this->formatSelectFields($sql);
        $sql = $this->formatInClauses($sql);
        $sql = $this->formatGroupByClause($sql);
        $sql = $this->handleCommas($sql);
        $sql = $this->indentNestedQueries($sql);
        
        // Apply consistent indentation after keywords
        $sql = $this->applyConsistentIndentation($sql);
        
        // Restore protected strings
        $sql = $this->restoreStrings($sql);
        
        return trim($sql);
    }
    
    private function applyConsistentIndentation(string $sql): string {
        // Define the keywords that should trigger indentation of following lines
        $keywords = [
            'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 
            'HAVING', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN',
            'OUTER JOIN', 'UNION', 'UNION ALL'
        ];
        
        // Split into lines for processing
        $lines = explode("\n", $sql);
        $result = [];
        $currentIndent = '';
        $parenStack = 0; // Track parenthesis nesting level
        $baseIndentStack = ['']; // Stack to track indentation at each nesting level
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Skip empty lines
            if (empty($trimmedLine)) {
                continue;
            }
            
            // Count opening and closing parentheses in this line
            $openCount = substr_count($trimmedLine, '(');
            $closeCount = substr_count($trimmedLine, ')');
            
            // Handle opening parentheses - increase nesting level
            if ($openCount > 0) {
                for ($i = 0; $i < $openCount; $i++) {
                    $parenStack++;
                    // Add one level of indentation for each open paren
                    $baseIndentStack[$parenStack] = $baseIndentStack[$parenStack - 1] . $this->indentation;
                }
            }
            
            // Check if this line starts with a keyword
            $isKeyword = false;
            foreach ($keywords as $keyword) {
                if (preg_match('/^' . preg_quote($keyword) . '\b/i', $trimmedLine)) {
                    // This line is a keyword
                    // Add it with the current nesting level indentation
                    $result[] = $baseIndentStack[$parenStack] . $keyword;
                    // Set indentation for following lines (keyword + one more level)
                    $currentIndent = $baseIndentStack[$parenStack] . $this->indentation;
                    $isKeyword = true;
                    break;
                }
            }
            
            // If not a keyword line, add with current indentation
            if (!$isKeyword) {
                $result[] = $currentIndent . $trimmedLine;
            }
            
            // Handle closing parentheses - decrease nesting level
            if ($closeCount > 0) {
                for ($i = 0; $i < $closeCount; $i++) {
                    if ($parenStack > 0) {
                        $parenStack--;
                        // When decreasing nesting level, adjust current indent
                        // to match the current nesting level
                        $currentIndent = $baseIndentStack[$parenStack] . $this->indentation;
                    }
                }
            }
        }
        
        return implode("\n", $result);
    }

    private function formatInClauses(string $sql): string {
        return preg_replace_callback(
            '/\bIN\s*\((.*?)\)/is',
            function($matches) {
                $content = trim($matches[1]);
                if (empty($content)) {
                    return 'IN ()';
                }

                // Split the contents by commas, respecting nested parentheses and functions
                $values = $this->splitValues($content);
                
                if (count($values) <= 1) {
                    return 'IN (' . $content . ')';
                }

                // Format each value with proper indentation
                $formattedValues = array_map('trim', $values);
                
                // Add extra indentation for IN clause contents
                $extraIndent = $this->indentation . $this->indentation;
                
                return "IN (\n" . $extraIndent . implode(",\n" . $extraIndent, $formattedValues) . "\n" . $this->indentation . ")";
            },
            $sql
        );
    }

    private function splitValues(string $content): array {
        $values = [];
        $currentValue = '';
        $parenCount = 0;
        $length = strlen($content);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            
            if ($char === '(') {
                $parenCount++;
            } elseif ($char === ')') {
                $parenCount--;
            }
            
            if ($char === ',' && $parenCount === 0) {
                $values[] = trim($currentValue);
                $currentValue = '';
            } else {
                $currentValue .= $char;
            }
        }
        
        if (trim($currentValue) !== '') {
            $values[] = trim($currentValue);
        }
        
        return $values;
    }

    private function formatSelectFields(string $sql): string {
        $parts = preg_split('/\b(SELECT|FROM)\b/i', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        
        for ($i = 0; $i < count($parts); $i++) {
            if (strtoupper($parts[$i]) === 'SELECT') {
                // Add SELECT keyword
                $result .= $parts[$i];
                
                // Get the fields part (next part until FROM)
                if (isset($parts[$i + 1])) {
                    $fieldsSection = $parts[$i + 1];
                    
                    // Split fields by commas, but not within functions
                    $fields = $this->splitFields($fieldsSection);
                    
                    // Format each field
                    $formattedFields = array_map('trim', $fields);
                    
                    // Join fields with newline and proper indentation
                    $result .= "\n" . $this->indentation . implode(",\n" . $this->indentation, $formattedFields);
                }
            } elseif (strtoupper($parts[$i]) === 'FROM') {
                // Add FROM and the rest
                $result .= "\n" . $parts[$i] . (isset($parts[$i + 1]) ? $parts[$i + 1] : '');
            } elseif ($i === 0) {
                // Add any preceding parts
                $result .= $parts[$i];
            }
        }
        
        return $result;
    }

    private function splitFields(string $fieldsSection): array {
        $fields = [];
        $currentField = '';
        $parenCount = 0;
        $length = strlen($fieldsSection);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $fieldsSection[$i];
            
            if ($char === '(') {
                $parenCount++;
            } elseif ($char === ')') {
                $parenCount--;
            }
            
            if ($char === ',' && $parenCount === 0) {
                $fields[] = trim($currentField);
                $currentField = '';
            } else {
                $currentField .= $char;
            }
        }
        
        if (trim($currentField) !== '') {
            $fields[] = trim($currentField);
        }
        
        return $fields;
    }

    private function formatGroupByClause(string $sql): string {
        return preg_replace_callback(
            '/\bGROUP\s+BY\s+(.*?)(?=\b(?:HAVING|ORDER BY|LIMIT|$))/is',
            function($matches) {
                $groupByFields = $this->splitGroupByFields($matches[1]);
                $formattedFields = array_map('trim', $groupByFields);
                
                return "\nGROUP BY\n" . $this->indentation . 
                       implode(",\n" . $this->indentation, $formattedFields);
            },
            $sql
        );
    }

    private function splitGroupByFields(string $content): array {
        $fields = [];
        $currentField = '';
        $parenCount = 0;
        $length = strlen($content);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            
            if ($char === '(') {
                $parenCount++;
            } elseif ($char === ')') {
                $parenCount--;
            }
            
            if ($char === ',' && $parenCount === 0) {
                $fields[] = trim($currentField);
                $currentField = '';
            } else {
                $currentField .= $char;
            }
        }
        
        if (trim($currentField) !== '') {
            $fields[] = trim($currentField);
        }
        
        return $fields;
    }

    private function protectStrings(string $sql): string {
        $this->stringMap = [];
        $this->placeholder = 0;
        
        // Protect single-quoted strings
        $sql = preg_replace_callback(
            "/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/s",
            function($matches) {
                $placeholder = "###STRING" . $this->placeholder++ . "###";
                $this->stringMap[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        // Protect double-quoted strings
        $sql = preg_replace_callback(
            '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/s',
            function($matches) {
                $placeholder = "###STRING" . $this->placeholder++ . "###";
                $this->stringMap[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        return $sql;
    }

    private function restoreStrings(string $sql): string {
        return str_replace(
            array_keys($this->stringMap),
            array_values($this->stringMap),
            $sql
        );
    }

    private function formatKeywords(string $sql): string {
        // Create a pattern for all major and clause keywords
        $keywordPattern = '/\b(SELECT|FROM|WHERE|GROUP BY|ORDER BY|HAVING|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN|UNION|UNION ALL)\b/i';
        
        // Split SQL by keywords to process each section
        $parts = preg_split($keywordPattern, $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        
        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            
            // If this is a keyword
            if ($i % 2 === 1) {
                $keyword = strtoupper($part);
                $result .= "\n$keyword\n";
                
                // Get the content after the keyword
                if (isset($parts[$i + 1])) {
                    $content = trim($parts[$i + 1]);
                    
                    // Format the content with indentation
                    $lines = explode("\n", $content);
                    $indentedLines = [];
                    
                    foreach ($lines as $line) {
                        if (trim($line) !== '') {
                            $indentedLines[] = $this->indentation . trim($line);
                        }
                    }
                    
                    $result .= implode("\n", $indentedLines);
                    $i++; // Skip the next part as we've already processed it
                }
            } else {
                // This is the content before any keyword
                if ($i === 0 && trim($part) !== '') {
                    $result .= trim($part);
                }
            }
        }
        
        return $result;
    }

    private function handleCommas(string $sql): string {
        $result = '';
        $depth = 0;
        $inFunction = false;
        $inSelect = false;
        $length = strlen($sql);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            
            if (preg_match('/\bSELECT\b/i', substr($sql, max(0, $i - 6), 6))) {
                $inSelect = true;
            } elseif (preg_match('/\bFROM\b/i', substr($sql, max(0, $i - 4), 4))) {
                $inSelect = false;
            }
            
            if ($char === '(') {
                $depth++;
                $prefix = substr($sql, max(0, $i - 20), 20);
                if (preg_match('/\b(' . implode('|', $this->keywords['FUNCTIONS']) . ')\s*$/i', $prefix)) {
                    $inFunction = true;
                }
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $inFunction = false;
                }
            }
            
            if ($char === ',' && !$inFunction && $depth === 0 && !$inSelect) {
                $result .= ",\n$this->indentation";
            } else {
                $result .= $char;
            }
        }
        
        return $result;
    }

    private function indentNestedQueries(string $sql): string {
        $lines = explode("\n", $sql);
        $indentLevel = 0;
        $formattedLines = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            $openCount = substr_count($trimmedLine, '(');
            $closeCount = substr_count($trimmedLine, ')');
            
            if (!empty($trimmedLine)) {
                $currentIndent = str_repeat($this->indentation, $indentLevel);
                $formattedLines[] = $currentIndent . $trimmedLine;
            }
            
            $indentLevel += $openCount - $closeCount;
            $indentLevel = max(0, $indentLevel);
        }
        
        return implode("\n", $formattedLines);
    }

    public function setIndentation(string $indentation): void {
        $this->indentation = $indentation;
    }
}

// API endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['input-query']) || empty($_POST['input-query'])) {
            throw new InvalidArgumentException('Input query is required');
        }

        $formatter = new SQLFormatter();
        
        if (isset($_POST['indentation'])) {
            $formatter->setIndentation($_POST['indentation']);
        }
        
        $formattedQuery = $formatter->format($_POST['input-query']);
        
        header('Content-Type: text/plain; charset=UTF-8');
        echo $formattedQuery;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo $e->getMessage();
    }
}
