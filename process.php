<?php
class SQLFormatter {
    private array $keywords;
    private string $indentation = "\t";
    private array $protectedBlocks = [];
    private int $placeholder = 0;
    private int $maxQuerySize = 1048576; // 1MB limit for queries
    
    public function __construct() {
        $this->keywords = [
            'MAJOR_KEYWORDS' => [
                'SELECT', 'INSERT', 'UPDATE', 'DELETE',
                'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'WITH'
            ],
            'CLAUSE_KEYWORDS' => [
                'FROM', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY',
                'LIMIT', 'OFFSET', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN',
                'INNER JOIN', 'OUTER JOIN', 'UNION', 'UNION ALL',
                'PARTITION BY', 'OVER'
            ],
            'LOGICAL_OPERATORS' => [
                'AND', 'OR', 'NOT', 'IN', 'EXISTS', 'BETWEEN',
                'LIKE', 'IS NULL', 'IS NOT NULL'
            ],
            'FUNCTIONS' => [
                'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE',
                'CONCAT', 'OVER', 'RANK', 'LEAD', 'LAG', 'DATE_SUB',
                'ROW_NUMBER', 'NTILE', 'DENSE_RANK'
            ]
        ];
    }

    public function format(string $sql): string {
        // Input validation
        if (empty(trim($sql))) {
            return '';
        }
        
        // Limit query size to prevent resource exhaustion
        if (strlen($sql) > $this->maxQuerySize) {
            throw new RuntimeException('Query exceeds maximum allowed size');
        }
        
        // First, ensure there's a space before GROUP BY
        $sql = preg_replace('/(\S)(GROUP\s+BY\b)/i', '$1 $2', trim($sql));
        
        // Normalize other whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Protect strings, comments, and special identifiers
        $sql = $this->protectBlocks($sql);
        
        // Apply formatting to get the basic structure
        // Handle WITH clauses first as they appear at the beginning
        $sql = $this->formatWithClauses($sql);
        $sql = $this->formatKeywords($sql);
        $sql = $this->formatSelectFields($sql);
        $sql = $this->formatInClauses($sql);
        
        
        $sql = $this->formatGroupByClause($sql);
        $sql = $this->formatOrderByClause($sql);
        $sql = $this->formatWindowFunctions($sql);
        $sql = $this->handleCommas($sql);
        $sql = $this->indentNestedQueries($sql);

        // Ensure proper separation between major clauses
        $sql = $this->ensureMajorClauseSeparation($sql);
        
        // Apply consistent indentation after keywords
        $sql = $this->applyConsistentIndentation($sql);
        
        // Restore protected blocks
        $sql = $this->restoreBlocks($sql);
        
        return trim($sql);
    }

    // Format WITH clauses (Common Table Expressions)
    private function formatWithClauses(string $sql): string {
        if (!preg_match('/^\s*WITH\b/i', $sql)) {
            return $sql; // No WITH clause
        }
        
        return preg_replace_callback(
            '/^\s*WITH\s+(.*?)(\s+SELECT\b|$)/is',
            function($matches) {
                $cteContent = $matches[1];
                $afterCte = $matches[2];
                
                // Split CTEs by comma when not inside parentheses
                $ctes = $this->splitCteDefinitions($cteContent);
                
                if (count($ctes) <= 1) {
                    return 'WITH ' . $cteContent . $afterCte;
                }
                
                $formattedCtes = [];
                foreach ($ctes as $cte) {
                    $formattedCtes[] = $this->indentation . trim($cte);
                }
                
                return "WITH\n" . implode(",\n", $formattedCtes) . $afterCte;
            },
            $sql
        );
    }
    
    private function splitCteDefinitions(string $content): array {
        $ctes = [];
        $currentCte = '';
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
                $ctes[] = trim($currentCte);
                $currentCte = '';
            } else {
                $currentCte .= $char;
            }
        }
        
        if (trim($currentCte) !== '') {
            $ctes[] = trim($currentCte);
        }
        
        return $ctes;
    }

    // Format window functions with OVER and PARTITION BY
    private function formatWindowFunctions(string $sql): string {
        return preg_replace_callback(
            '/\bOVER\s*\((.*?)\)/is',
            function($matches) {
                $content = trim($matches[1]);
                
                // If empty or very simple, don't format
                if (empty($content) || !preg_match('/PARTITION BY|ORDER BY/i', $content)) {
                    return 'OVER (' . $content . ')';
                }
                
                // Format PARTITION BY
                $content = preg_replace_callback(
                    '/\bPARTITION\s+BY\s+(.*?)(?=\bORDER BY\b|$)/is',
                    function($partMatches) {
                        $partFields = $this->splitFields($partMatches[1]);
                        $formatted = "PARTITION BY\n" . $this->indentation . $this->indentation . 
                            implode(",\n" . $this->indentation . $this->indentation, array_map('trim', $partFields));
                        return $formatted;
                    },
                    $content
                );
                
                // Format ORDER BY within OVER
                $content = preg_replace_callback(
                    '/\bORDER\s+BY\s+(.*?)(?=$)/is',
                    function($orderMatches) {
                        $orderFields = $this->splitFields($orderMatches[1]);
                        $formatted = "ORDER BY\n" . $this->indentation . $this->indentation . 
                            implode(",\n" . $this->indentation . $this->indentation, array_map('trim', $orderFields));
                        return $formatted;
                    },
                    $content
                );
                
                return "OVER (\n" . $this->indentation . $content . "\n)";
            },
            $sql
        );
    }

    // Format ORDER BY clauses
    private function formatOrderByClause(string $sql): string {
        return preg_replace_callback(
            '/\bORDER\s+BY\s+(.*?)(?=\bLIMIT\b|$)/is',
            function($matches) {
                $orderByFields = $this->splitFields($matches[1]);
                $formattedFields = array_map('trim', $orderByFields);
                
                return "\nORDER BY\n" . $this->indentation . 
                       implode(",\n" . $this->indentation, $formattedFields);
            },
            $sql
        );
    }

    // Make sure major clauses have line breaks
    private function ensureMajorClauseSeparation(string $sql): string {
        $keywordPattern = '/(\S)(\s*)(GROUP BY|ORDER BY|LIMIT|HAVING|UNION|UNION ALL|WITH)\b/i';
        return preg_replace($keywordPattern, '$1$2' . "\n" . '$3', $sql);
    }
    
    private function applyConsistentIndentation(string $sql): string {
        // Define the keywords that should trigger indentation of following lines
        $keywords = [
            'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 
            'HAVING', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN',
            'OUTER JOIN', 'UNION', 'UNION ALL', 'WITH', 'PARTITION BY'
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

    private function formatWhereClause(string $sql): string {
        // Locate the WHERE keyword position
        if (!preg_match('/\bWHERE\b/i', $sql)) {
            return $sql; // No WHERE clause found
        }
        
        // Split SQL into parts: before WHERE, WHERE clause itself, and everything after
        $parts = preg_split('/\b(WHERE)\b/i', $sql, 2, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 3) {
            return $sql; // Something went wrong with the split
        }
        
        $beforeWhere = $parts[0];
        $whereKeyword = $parts[1]; // This will be "WHERE"
        $afterWhereAll = $parts[2]; // This contains the WHERE conditions AND all following clauses
        
        // Now split the after-WHERE part to separate the conditions from later clauses
        $nextClauseKeywords = ['GROUP BY', 'ORDER BY', 'LIMIT', 'HAVING', 'UNION', 'UNION ALL'];
        $whereConditions = $afterWhereAll;
        $afterClauses = '';
        
        // Find the first occurrence of any next clause keyword
        $firstNextClausePos = PHP_INT_MAX;
        $matchedKeyword = '';
        
        foreach ($nextClauseKeywords as $keyword) {
            $pos = stripos($afterWhereAll, $keyword);
            if ($pos !== false && $pos < $firstNextClausePos) {
                // Check this is a standalone keyword, not part of another word
                $wordBoundaryPattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
                if (preg_match($wordBoundaryPattern, $afterWhereAll, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos = $matches[0][1];
                    $firstNextClausePos = $pos;
                    $matchedKeyword = $keyword;
                }
            }
        }
        
        // If we found a next clause, split the WHERE conditions from later clauses
        if ($firstNextClausePos < PHP_INT_MAX) {
            $whereConditions = substr($afterWhereAll, 0, $firstNextClausePos);
            $afterClauses = substr($afterWhereAll, $firstNextClausePos);
        }
        
        // Special handling for incorrect SQL where first condition starts with AND/OR
        $whereConditions = trim($whereConditions);
        if (preg_match('/^\s*(AND|OR)\b\s*/i', $whereConditions)) {
            // Remove the first AND/OR as it's a common SQL editing mistake
            $whereConditions = preg_replace('/^\s*(AND|OR)\b\s*/i', '', $whereConditions);
        }
        
        // Format the WHERE conditions
        $formattedConditions = $this->formatAndConditions($whereConditions);
        
        // Reassemble the SQL with the formatted WHERE clause and preserving everything else
        return $beforeWhere . $whereKeyword . $formattedConditions . $afterClauses;
    }

    private function tokenizeWhereClause(string $whereClause): array {
        $tokens = [];
        $currentToken = '';
        $parenLevel = 0;
        $length = strlen($whereClause);
        $inString = false;
        $stringChar = '';
        
        // Trim leading/trailing whitespace
        $whereClause = trim($whereClause);
        
        $i = 0;
        while ($i < $length) {
            $char = $whereClause[$i];
            $nextChar = ($i + 1 < $length) ? $whereClause[$i + 1] : '';
            
            // Handle string literals
            if (($char === "'" || $char === '"' || $char === '`') && ($i === 0 || $whereClause[$i-1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
            }
            
            // Track parenthesis nesting level (only when not in a string)
            if (!$inString) {
                if ($char === '(') {
                    $parenLevel++;
                } elseif ($char === ')') {
                    $parenLevel--;
                }
                
                // Look for AND/OR operators at the top level
                if ($parenLevel === 0 && !$inString) {
                    // Look for AND pattern, ensuring it's a whole word
                    $andMatch = false;
                    if ($i === 0 && strtoupper(substr($whereClause, 0, 4)) === 'AND ') {
                        $andMatch = true;
                        $tokens[] = "AND"; // Operator itself
                        $i += 4; // Skip "AND "
                        $currentToken = '';
                        continue;
                    } elseif ($i > 0 && strtoupper(substr($whereClause, $i-1, 5)) === ' AND ') {
                        $andMatch = true;
                        // Add the token before AND if not empty
                        if (!empty(trim($currentToken))) {
                            $tokens[] = trim($currentToken);
                        }
                        $currentToken = '';
                        $tokens[] = "AND"; // Operator itself
                        $i += 4; // Skip "AND "
                        continue;
                    }
                    
                    // Look for OR pattern, ensuring it's a whole word
                    $orMatch = false;
                    if ($i === 0 && strtoupper(substr($whereClause, 0, 3)) === 'OR ') {
                        $orMatch = true;
                        $tokens[] = "OR"; // Operator itself
                        $i += 3; // Skip "OR "
                        $currentToken = '';
                        continue;
                    } elseif ($i > 0 && strtoupper(substr($whereClause, $i-1, 4)) === ' OR ') {
                        $orMatch = true;
                        // Add the token before OR if not empty
                        if (!empty(trim($currentToken))) {
                            $tokens[] = trim($currentToken);
                        }
                        $currentToken = '';
                        $tokens[] = "OR"; // Operator itself
                        $i += 3; // Skip "OR "
                        continue;
                    }
                }
            }
            
            // Add current character to token
            $currentToken .= $char;
            $i++;
        }
        
        // Add the last token if any
        if (trim($currentToken) !== '') {
            $tokens[] = trim($currentToken);
        }
        
        return $tokens;
    }
    
    private function formatAndConditions(string $whereClause): string {
        // Normalize spaces
        $whereClause = trim($whereClause);
        $whereClause = preg_replace('/\s+AND\s+/i', ' AND ', $whereClause);
        $whereClause = preg_replace('/\s+OR\s+/i', ' OR ', $whereClause);
        
        // If no logical operators, just return the clause
        if (stripos($whereClause, ' AND ') === false && stripos($whereClause, ' OR ') === false &&
            stripos($whereClause, 'AND ') !== 0 && stripos($whereClause, 'OR ') !== 0) {
            return ' ' . $whereClause;
        }
        
        // Split the where clause into tokens
        $tokens = $this->tokenizeWhereClause($whereClause);
        
        // If tokenization failed or no tokens, return original
        if (empty($tokens)) {
            return ' ' . $whereClause;
        }
        
        // Check if we start with an operator
        $startsWithOperator = (strtoupper($tokens[0]) === 'AND' || strtoupper($tokens[0]) === 'OR');
        
        // Build the formatted result
        $result = '';
        $currentIndent = $this->indentation;
        $currentOperator = null;
        $inSubquery = false;
        $parenLevel = 0;
        
        // Process all tokens
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $isOperator = (strtoupper($token) === 'AND' || strtoupper($token) === 'OR');
            
            if ($isOperator) {
                $currentOperator = strtoupper($token);
                // Don't add anything yet, wait for the condition
            } else {
                // This is a condition
                
                // Count nested parentheses
                $openParens = substr_count($token, '(');
                $closeParens = substr_count($token, ')');
                $parenDiff = $openParens - $closeParens;
                
                // Handle nesting for indentation
                if ($parenDiff > 0) {
                    $inSubquery = true;
                } elseif ($parenDiff < 0 && $parenLevel + $parenDiff <= 0) {
                    $inSubquery = false;
                }
                $parenLevel += $parenDiff;
                
                // Determine indentation
                $nestedIndent = $inSubquery ? $currentIndent . $this->indentation : $currentIndent;
                
                // First condition without operator
                if ($result === '' && !$startsWithOperator) {
                    $result = ' ' . $token;
                } else {
                    // Add operator + condition
                    if ($currentOperator) {
                        $result .= "\n" . $nestedIndent . $currentOperator . " " . $token;
                        $currentOperator = null;
                    } else {
                        // This shouldn't happen with proper tokenization
                        $result .= " " . $token;
                    }
                }
            }
        }
        
        return $result;
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

    private function protectBlocks(string $sql): string {
        $this->protectedBlocks = [];
        $this->placeholder = 0;
        
        // Protect single-quoted strings
        $sql = preg_replace_callback(
            "/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/s",
            function($matches) {
                $placeholder = "##SQL_FORMATTER_STRING_" . $this->placeholder++ . "##";
                $this->protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        // Protect double-quoted strings
        $sql = preg_replace_callback(
            '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/s',
            function($matches) {
                $placeholder = "##SQL_FORMATTER_DSTRING_" . $this->placeholder++ . "##";
                $this->protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        // Protect backtick identifiers
        $sql = preg_replace_callback(
            '/`[^`\\\\]*(?:\\\\.[^`\\\\]*)*`/s',
            function($matches) {
                $placeholder = "##SQL_FORMATTER_BTICK_" . $this->placeholder++ . "##";
                $this->protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        // Protect -- style comments
        $sql = preg_replace_callback(
            '/--[^\r\n]*(?:\r\n|\r|\n|$)/s',
            function($matches) {
                $placeholder = "##SQL_FORMATTER_COMMENT1_" . $this->placeholder++ . "##";
                $this->protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        // Protect /* */ style comments
        $sql = preg_replace_callback(
            '/\/\*[\s\S]*?\*\//s',
            function($matches) {
                $placeholder = "##SQL_FORMATTER_COMMENT2_" . $this->placeholder++ . "##";
                $this->protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $sql
        );
        
        return $sql;
    }

    private function restoreBlocks(string $sql): string {
        return str_replace(
            array_keys($this->protectedBlocks),
            array_values($this->protectedBlocks),
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
        // Validate indentation to only allow tabs or spaces
        if (!preg_match('/^[\t ]+$/', $indentation) && $indentation !== '') {
            throw new InvalidArgumentException('Indentation can only contain tabs or spaces');
        }
        $this->indentation = $indentation;
    }
    
    public function setMaxQuerySize(int $maxSize): void {
        if ($maxSize < 1024 || $maxSize > 10485760) { // Between 1KB and 10MB
            throw new InvalidArgumentException('Max query size must be between 1KB and 10MB');
        }
        $this->maxQuerySize = $maxSize;
    }
}

// API endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Set appropriate headers
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        
        // Validate CSRF token if implemented
        // if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        //     throw new RuntimeException('Invalid CSRF token');
        // }
        
        if (!isset($_POST['input-query']) || empty(trim($_POST['input-query']))) {
            throw new InvalidArgumentException('Input query is required');
        }

        $formatter = new SQLFormatter();
        
        if (isset($_POST['indentation'])) {
            $formatter->setIndentation($_POST['indentation']);
        }
        
        $formattedQuery = $formatter->format($_POST['input-query']);
        
        echo $formattedQuery;
        
    } catch (Exception $e) {
        http_response_code(400);
        // Provide generic error message for security
        echo 'Error processing request: ' . $e->getMessage();
    }
}