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
        
        // Format CASE statements
        $sql = $this->formatCaseStatements($sql);

        // PHASE 1 FIX: Format IN clauses BEFORE WHERE clause
        // This ensures IN clauses are properly formatted but won't interfere with WHERE logic
        $sql = $this->formatInClausesOutsideWhere($sql);
        
        // PHASE 1 FIX: Add WHERE clause formatting
        $sql = $this->formatWhereClause($sql);
        
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

    // CASE STATEMENT FORMATTING METHODS
    private function formatCaseStatements(string $sql): string {
        // Simple approach: find complete CASE...END blocks and format them one by one
        $result = $sql;
        $maxIterations = 5;
        $iteration = 0;
        
        // Keep formatting until no more CASE statements need formatting
        while ($iteration < $maxIterations) {
            $oldResult = $result;
            $result = $this->formatNextCaseStatement($result);
            
            // If nothing changed, we're done
            if ($result === $oldResult) {
                break;
            }
            $iteration++;
        }
        
        return $result;
    }

    private function formatCaseBlockSimple(string $caseBlock): string {
        $caseBlock = trim($caseBlock);
        
        // Remove the outer CASE and END
        $content = preg_replace('/^\s*CASE\s*/i', '', $caseBlock);
        $content = preg_replace('/\s*END\s*$/i', '', $content);
        
        // Split into tokens manually
        $tokens = $this->tokenizeCaseContent($content);
        
        $result = "CASE";
        $currentWhen = '';
        $currentThen = '';
        $inWhen = false;
        $inThen = false;
        $elseContent = '';
        
        foreach ($tokens as $token) {
            $upperToken = strtoupper(trim($token));
            
            if ($upperToken === 'WHEN') {
                // Finish previous WHEN/THEN if any
                if ($inThen && !empty($currentWhen) && !empty($currentThen)) {
                    $result .= "\n" . $this->indentation . "WHEN " . trim($currentWhen) . " THEN " . trim($currentThen);
                    $currentWhen = '';
                    $currentThen = '';
                }
                $inWhen = true;
                $inThen = false;
            } elseif ($upperToken === 'THEN') {
                $inWhen = false;
                $inThen = true;
            } elseif ($upperToken === 'ELSE') {
                // Finish current WHEN/THEN
                if ($inThen && !empty($currentWhen) && !empty($currentThen)) {
                    $result .= "\n" . $this->indentation . "WHEN " . trim($currentWhen) . " THEN " . trim($currentThen);
                    $currentWhen = '';
                    $currentThen = '';
                }
                $inWhen = false;
                $inThen = false;
            } else {
                // Regular content
                if ($inWhen) {
                    $currentWhen .= ' ' . $token;
                } elseif ($inThen) {
                    $currentThen .= ' ' . $token;
                } elseif (!$inWhen && !$inThen) {
                    // This is ELSE content
                    $elseContent .= ' ' . $token;
                }
            }
        }
        
        // Finish the last WHEN/THEN
        if (!empty($currentWhen) && !empty($currentThen)) {
            $result .= "\n" . $this->indentation . "WHEN " . trim($currentWhen) . " THEN " . trim($currentThen);
        }
        
        // Add ELSE if present
        if (!empty(trim($elseContent))) {
            $result .= "\n" . $this->indentation . "ELSE " . trim($elseContent);
        }
        
        $result .= "\nEND";
        
        return $result;
    }

    private function findMatchingEndSimple(string $sql, int $caseStart) {
        $caseCount = 0;
        $i = $caseStart;
        $length = strlen($sql);
        $inQuote = false;
        $quoteChar = '';
        
        while ($i < $length - 2) {
            $char = $sql[$i];
            
            // Handle quotes
            if (!$inQuote && ($char === "'" || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote = false;
            }
            
            if (!$inQuote) {
                // Check for CASE
                if (strtoupper(substr($sql, $i, 4)) === 'CASE' && 
                    (!ctype_alnum($sql[$i-1] ?? ' ')) && 
                    (!ctype_alnum($sql[$i+4] ?? ' '))) {
                    $caseCount++;
                    $i += 4;
                    continue;
                }
                
                // Check for END
                if (strtoupper(substr($sql, $i, 3)) === 'END' && 
                    (!ctype_alnum($sql[$i-1] ?? ' ')) && 
                    (!ctype_alnum($sql[$i+3] ?? ' '))) {
                    $caseCount--;
                    if ($caseCount === 0) {
                        return $i;
                    }
                    $i += 3;
                    continue;
                }
            }
            
            $i++;
        }
        
        return false;
    }
    
    private function isCaseAlreadyFormatted(string $caseBlock): bool {
        // Simple check: if it contains newlines with proper indentation, consider it formatted
        return (strpos($caseBlock, "\n" . $this->indentation . "WHEN") !== false);
    }
    
    private function tokenizeCaseContent(string $content): array {
        $tokens = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $length = strlen($content);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            
            if (!$inQuote && ($char === "'" || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote = false;
                $current .= $char;
            } elseif (!$inQuote) {
                // Check for keywords
                $remaining = substr($content, $i);
                if (preg_match('/^(WHEN|THEN|ELSE)\b/i', $remaining, $matches)) {
                    if (!empty(trim($current))) {
                        $tokens[] = trim($current);
                        $current = '';
                    }
                    $tokens[] = $matches[1];
                    $i += strlen($matches[1]) - 1; // -1 because loop will increment
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }
        
        if (!empty(trim($current))) {
            $tokens[] = trim($current);
        }
        
        return $tokens;
    }

    private function formatNextCaseStatement(string $sql): string {
        // Find the first CASE statement that needs formatting
        $casePos = stripos($sql, 'CASE');
        if ($casePos === false) {
            return $sql; // No CASE found
        }
        
        // Find the matching END for this CASE
        $endPos = $this->findMatchingEndSimple($sql, $casePos);
        if ($endPos === false) {
            return $sql; // No matching END found
        }
        
        // Extract the CASE block
        $beforeCase = substr($sql, 0, $casePos);
        $caseBlock = substr($sql, $casePos, $endPos - $casePos + 3); // +3 for "END"
        $afterCase = substr($sql, $endPos + 3);
        
        // Check if this CASE block is already well-formatted
        if ($this->isCaseAlreadyFormatted($caseBlock)) {
            // Skip this one, look for the next
            $remainingPart = $this->formatNextCaseStatement($afterCase);
            return $beforeCase . $caseBlock . $remainingPart;
        }
        
        // Format this CASE block
        $formattedCase = $this->formatCaseBlockSimple($caseBlock);
        
        return $beforeCase . $formattedCase . $afterCase;
    }

    private function findMatchingEnd(string $sql, int $caseStart) {
        $caseCount = 1; // Start with 1 since we're already at a CASE
        $i = $caseStart + 4; // Start after the initial CASE
        $length = strlen($sql);
        $inQuote = false;
        $quoteChar = '';
        
        while ($i < $length) {
            $char = $sql[$i];
            
            // Handle quotes
            if (!$inQuote && ($char === "'" || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar && ($i === 0 || $sql[$i-1] !== '\\')) {
                $inQuote = false;
                $quoteChar = '';
            }
            
            if (!$inQuote) {
                // Check for CASE keyword (word boundary check)
                if ($i + 4 <= $length && strtoupper(substr($sql, $i, 4)) === 'CASE') {
                    $beforeChar = ($i > 0) ? $sql[$i-1] : ' ';
                    $afterChar = ($i + 4 < $length) ? $sql[$i + 4] : ' ';
                    if (!ctype_alnum($beforeChar) && !ctype_alnum($afterChar)) {
                        $caseCount++;
                        $i += 4;
                        continue;
                    }
                }
                
                // Check for END keyword (word boundary check)
                if ($i + 3 <= $length && strtoupper(substr($sql, $i, 3)) === 'END') {
                    $beforeChar = ($i > 0) ? $sql[$i-1] : ' ';
                    $afterChar = ($i + 3 < $length) ? $sql[$i + 3] : ' ';
                    if (!ctype_alnum($beforeChar) && !ctype_alnum($afterChar)) {
                        $caseCount--;
                        if ($caseCount === 0) {
                            return $i; // Found matching END
                        }
                        $i += 3;
                        continue;
                    }
                }
            }
            
            $i++;
        }
        
        return false; // No matching END found
    }

    private function formatSingleCaseStatement(string $caseBlock): string {
        $caseBlock = trim($caseBlock);
        
        // Check if this is a simple CASE or searched CASE
        $isSimpleCase = $this->isSimpleCaseStatement($caseBlock);
        
        // Extract components
        $components = $this->parseCaseComponents($caseBlock, $isSimpleCase);
        
        if (empty($components)) {
            return $caseBlock; // Return original if parsing fails
        }
        
        // Build formatted CASE statement
        $result = "CASE";
        
        // Add expression for simple CASE
        if ($isSimpleCase && !empty($components['expression'])) {
            $result .= " " . trim($components['expression']);
        }
        
        // Add WHEN clauses
        foreach ($components['when_clauses'] as $whenClause) {
            $condition = $whenClause['condition'];
            $thenResult = $whenClause['result'];
            
            // Check if the THEN result contains a nested CASE statement
            if (preg_match('/\bCASE\b.*?\bEND\b/i', $thenResult)) {
                // Format nested CASE with extra indentation
                $nestedCase = $this->formatCaseStatements($thenResult);
                // Add extra indentation to each line of the nested CASE
                $lines = explode("\n", $nestedCase);
                $indentedLines = [];
                foreach ($lines as $line) {
                    if (trim($line) !== '') {
                        $indentedLines[] = $this->indentation . $this->indentation . trim($line);
                    }
                }
                $thenResult = "\n" . implode("\n", $indentedLines);
                $result .= "\n" . $this->indentation . "WHEN " . $condition . " THEN" . $thenResult;
            } else {
                // Regular THEN result
                $result .= "\n" . $this->indentation . "WHEN " . $condition . " THEN " . $thenResult;
            }
        }
        
        // Add ELSE clause if present
        if (!empty($components['else_clause'])) {
            $elseClause = $components['else_clause'];
            
            // Check if ELSE contains nested CASE
            if (preg_match('/\bCASE\b.*?\bEND\b/i', $elseClause)) {
                $nestedCase = $this->formatCaseStatements($elseClause);
                $lines = explode("\n", $nestedCase);
                $indentedLines = [];
                foreach ($lines as $line) {
                    if (trim($line) !== '') {
                        $indentedLines[] = $this->indentation . $this->indentation . trim($line);
                    }
                }
                $elseClause = "\n" . implode("\n", $indentedLines);
                $result .= "\n" . $this->indentation . "ELSE" . $elseClause;
            } else {
                $result .= "\n" . $this->indentation . "ELSE " . $elseClause;
            }
        }
        
        // Add END
        $result .= "\nEND";
        
        return $result;
    }

    private function isSimpleCaseStatement(string $caseBlock): bool {
        // Remove CASE and END keywords
        $content = preg_replace('/^\s*CASE\s+/i', '', $caseBlock);
        $content = preg_replace('/\s+END\s*$/i', '', $content);
        
        // Look for the pattern: expression WHEN value THEN result
        // If first WHEN doesn't start with a comparison operator, it's likely a simple CASE
        if (preg_match('/^([^W]+?)\s+WHEN\s+([^T]+?)\s+THEN/i', $content, $matches)) {
            $possibleExpression = trim($matches[1]);
            $whenValue = trim($matches[2]);
            
            // If the WHEN value doesn't contain comparison operators, it's likely simple CASE
            if (!preg_match('/[<>=!]|IS\s+NULL|IS\s+NOT\s+NULL|LIKE|IN\s*\(|EXISTS|BETWEEN/i', $whenValue)) {
                return true;
            }
        }
        
        return false;
    }

    private function parseCaseComponents(string $caseBlock, bool $isSimpleCase): array {
        $components = [
            'expression' => '',
            'when_clauses' => [],
            'else_clause' => ''
        ];
        
        // Remove CASE and END keywords
        $content = preg_replace('/^\s*CASE\s+/i', '', $caseBlock);
        $content = preg_replace('/\s+END\s*$/i', '', $content);
        
        // Extract expression for simple CASE
        if ($isSimpleCase) {
            if (preg_match('/^([^W]+?)\s+(?=WHEN)/i', $content, $matches)) {
                $components['expression'] = trim($matches[1]);
                $content = preg_replace('/^[^W]+?\s+(?=WHEN)/i', '', $content);
            }
        }
        
        // Extract ELSE clause first (if present)
        if (preg_match('/\bELSE\s+(.*?)$/is', $content, $elseMatches)) {
            $components['else_clause'] = trim($elseMatches[1]);
            $content = preg_replace('/\bELSE\s+.*$/is', '', $content);
        }
        
        // Extract WHEN/THEN clauses
        $whenClauses = $this->extractWhenClauses($content);
        $components['when_clauses'] = $whenClauses;
        
        return $components;
    }

    private function extractWhenClauses(string $content): array {
        $whenClauses = [];
        $content = trim($content);
        
        if (empty($content)) {
            return $whenClauses;
        }
        
        // Use a more robust approach to split WHEN clauses
        $i = 0;
        $length = strlen($content);
        
        while ($i < $length) {
            // Find next WHEN
            $whenPos = $this->findNextKeyword($content, 'WHEN', $i);
            if ($whenPos === false) {
                break;
            }
            
            // Find the THEN for this WHEN
            $thenPos = $this->findNextKeyword($content, 'THEN', $whenPos + 4);
            if ($thenPos === false) {
                break;
            }
            
            // Extract condition (between WHEN and THEN)
            $condition = trim(substr($content, $whenPos + 4, $thenPos - $whenPos - 4));
            
            // Find the end of the THEN value (next WHEN, ELSE, or end of string)
            $nextWhenPos = $this->findNextKeyword($content, 'WHEN', $thenPos + 4);
            $elsePos = $this->findNextKeyword($content, 'ELSE', $thenPos + 4);
            
            // Determine the end position
            $endPos = $length;
            if ($nextWhenPos !== false && $elsePos !== false) {
                $endPos = min($nextWhenPos, $elsePos);
            } elseif ($nextWhenPos !== false) {
                $endPos = $nextWhenPos;
            } elseif ($elsePos !== false) {
                $endPos = $elsePos;
            }
            
            // Extract result (between THEN and end position)
            $result = trim(substr($content, $thenPos + 4, $endPos - $thenPos - 4));
            
            $whenClauses[] = [
                'condition' => $condition,
                'result' => $result
            ];
            
            $i = $endPos;
        }
        
        return $whenClauses;
    }

    private function findNextWhenPosition(string $text) {
        // Look for WHEN that's not inside parentheses or quotes
        $parenCount = 0;
        $inQuote = false;
        $quoteChar = '';
        $length = strlen($text);
        
        for ($i = 0; $i < $length - 3; $i++) {
            $char = $text[$i];
            
            // Handle quotes
            if (!$inQuote && ($char === "'" || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar && ($i === 0 || $text[$i-1] !== '\\')) {
                $inQuote = false;
                $quoteChar = '';
            }
            
            if (!$inQuote) {
                // Handle parentheses
                if ($char === '(') {
                    $parenCount++;
                } elseif ($char === ')') {
                    $parenCount--;
                }
                
                // Look for WHEN at top level
                if ($parenCount === 0) {
                    $remaining = substr($text, $i);
                    if (preg_match('/^WHEN\s+/i', $remaining)) {
                        return $i;
                    }
                }
            }
        }
        
        return false;
    }

    private function findNextKeyword(string $text, string $keyword, int $startPos = 0) {
        $length = strlen($text);
        $keywordLen = strlen($keyword);
        $inQuote = false;
        $quoteChar = '';
        $parenCount = 0;
        $caseCount = 0;
        
        for ($i = $startPos; $i <= $length - $keywordLen; $i++) {
            $char = $text[$i];
            
            // Handle quotes
            if (!$inQuote && ($char === "'" || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar && ($i === 0 || $text[$i-1] !== '\\')) {
                $inQuote = false;
                $quoteChar = '';
            }
            
            if (!$inQuote) {
                // Handle parentheses
                if ($char === '(') {
                    $parenCount++;
                } elseif ($char === ')') {
                    $parenCount--;
                }
                
                // Handle nested CASE statements - only count at same nesting level
                if ($i + 4 <= $length && strtoupper(substr($text, $i, 4)) === 'CASE') {
                    $beforeChar = ($i > 0) ? $text[$i-1] : ' ';
                    $afterChar = ($i + 4 < $length) ? $text[$i + 4] : ' ';
                    if (!ctype_alnum($beforeChar) && !ctype_alnum($afterChar)) {
                        $caseCount++;
                    }
                } elseif ($i + 3 <= $length && strtoupper(substr($text, $i, 3)) === 'END') {
                    $beforeChar = ($i > 0) ? $text[$i-1] : ' ';
                    $afterChar = ($i + 3 < $length) ? $text[$i + 3] : ' ';
                    if (!ctype_alnum($beforeChar) && !ctype_alnum($afterChar)) {
                        $caseCount--;
                    }
                }
                
                // Look for keyword only at top level
                if ($parenCount === 0 && $caseCount === 0) {
                    $potentialKeyword = strtoupper(substr($text, $i, $keywordLen));
                    if ($potentialKeyword === strtoupper($keyword)) {
                        // Check word boundaries
                        $beforeChar = ($i > 0) ? $text[$i - 1] : ' ';
                        $afterChar = ($i + $keywordLen < $length) ? $text[$i + $keywordLen] : ' ';
                        
                        if (!ctype_alnum($beforeChar) && $beforeChar !== '_' && 
                            !ctype_alnum($afterChar) && $afterChar !== '_') {
                            return $i;
                        }
                    }
                }
            }
        }
        
        return false;
    }

    // PHASE 1 IMPROVEMENT: Enhanced WHERE clause formatting
    private function formatWhereClause(string $sql): string {
        // Check if there's a WHERE clause
        if (!preg_match('/\bWHERE\b/i', $sql)) {
            return $sql;
        }
        
        // More precise regex to capture WHERE clause content
        $pattern = '/\b(WHERE)\s+(.*?)(?=\s*(?:GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION(?:\s+ALL)?|;|$))/is';
        
        return preg_replace_callback(
            $pattern,
            function($matches) {
                $whereKeyword = $matches[1];
                $whereConditions = trim($matches[2]);
                
                // Remove leading AND/OR if present (common SQL error)
                $whereConditions = preg_replace('/^\s*(AND|OR)\s+/i', '', $whereConditions);
                
                // Format the conditions
                $formattedConditions = $this->formatLogicalConditions($whereConditions);
                
                return "\n" . $whereKeyword . $formattedConditions;
            },
            $sql
        );
    }

    // PHASE 1 IMPROVEMENT: New method for formatting logical conditions
    private function formatLogicalConditions(string $conditions): string {
        if (empty(trim($conditions))) {
            return '';
        }
        
        // Parse conditions into a tree structure
        $conditionTree = $this->parseConditions($conditions);
        
        // Format the tree back into SQL
        return $this->formatConditionTree($conditionTree, 0, true);
    }

    // PHASE 1 IMPROVEMENT: Parse conditions respecting parentheses and operators
    private function parseConditions(string $conditions): array {
        $tokens = $this->tokenizeConditions($conditions);
        return $this->buildConditionTree($tokens);
    }

    // PHASE 1 IMPROVEMENT: Enhanced tokenizer for WHERE conditions
    private function tokenizeConditions(string $conditions): array {
        $tokens = [];
        $current = '';
        $length = strlen($conditions);
        $i = 0;
        
        while ($i < $length) {
            // Skip whitespace
            while ($i < $length && ctype_space($conditions[$i])) {
                $current .= $conditions[$i];
                $i++;
            }
            
            if ($i >= $length) break;
            
            // Check for AND operator (with word boundary check)
            if ($i + 3 <= $length) {
                $threeChars = substr($conditions, $i, 3);
                $afterThree = ($i + 3 < $length) ? $conditions[$i + 3] : ' ';
                if (strtoupper($threeChars) === 'AND' && (ctype_space($afterThree) || $afterThree === '(')) {
                    // Save current token if any
                    if (!empty(trim($current))) {
                        $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                        $current = '';
                    }
                    $tokens[] = ['type' => 'operator', 'value' => 'AND'];
                    $i += 3;
                    continue;
                }
            }
            
            // Check for OR operator (with word boundary check)
            if ($i + 2 <= $length) {
                $twoChars = substr($conditions, $i, 2);
                $afterTwo = ($i + 2 < $length) ? $conditions[$i + 2] : ' ';
                if (strtoupper($twoChars) === 'OR' && (ctype_space($afterTwo) || $afterTwo === '(')) {
                    // Save current token if any
                    if (!empty(trim($current))) {
                        $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                        $current = '';
                    }
                    $tokens[] = ['type' => 'operator', 'value' => 'OR'];
                    $i += 2;
                    continue;
                }
            }
            
            // Handle parentheses
            if ($conditions[$i] === '(') {
                // Check if this is part of a function or IN clause
                $beforeParen = trim($current);
                if (preg_match('/\b(IN|[A-Z_]+)\s*$/i', $beforeParen)) {
                    // This is a function or IN clause, include parentheses in current token
                    $current .= '(';
                    $i++;
                    $parenDepth = 1;
                    
                    // Capture everything until matching close paren
                    while ($i < $length && $parenDepth > 0) {
                        if ($conditions[$i] === '(') {
                            $parenDepth++;
                        } elseif ($conditions[$i] === ')') {
                            $parenDepth--;
                        }
                        $current .= $conditions[$i];
                        $i++;
                    }
                    continue;
                } else {
                    // This is a grouping parenthesis
                    if (!empty(trim($current))) {
                        $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                        $current = '';
                    }
                    $tokens[] = ['type' => 'paren', 'value' => '('];
                    $i++;
                    continue;
                }
            }
            
            if ($conditions[$i] === ')') {
                if (!empty(trim($current))) {
                    $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                    $current = '';
                }
                $tokens[] = ['type' => 'paren', 'value' => ')'];
                $i++;
                continue;
            }
            
            // Check if we're at the start of a string literal
            if ($conditions[$i] === "'" || $conditions[$i] === '"') {
                $quote = $conditions[$i];
                $current .= $quote;
                $i++;
                
                // Continue until we find the closing quote
                while ($i < $length) {
                    $current .= $conditions[$i];
                    if ($conditions[$i] === $quote && ($i === 0 || $conditions[$i-1] !== '\\')) {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            
            // Regular character
            $current .= $conditions[$i];
            $i++;
        }
        
        // Don't forget the last token
        if (!empty(trim($current))) {
            $tokens[] = ['type' => 'condition', 'value' => trim($current)];
        }
        
        return $tokens;
    }

    // PHASE 1 IMPROVEMENT: Build condition tree from tokens
    private function buildConditionTree(array $tokens): array {
        if (empty($tokens)) {
            return [];
        }
        
        // Handle simple case - single condition
        if (count($tokens) === 1 && $tokens[0]['type'] === 'condition') {
            return ['type' => 'condition', 'value' => $tokens[0]['value']];
        }
        
        // Process parentheses first
        $processed = $this->processParentheses($tokens);
        
        // Then process OR operators (lower precedence)
        $processed = $this->processOperator($processed, 'OR');
        
        // Then process AND operators (higher precedence)
        $processed = $this->processOperator($processed, 'AND');
        
        return $processed;
    }

    // PHASE 1 IMPROVEMENT: Process parentheses in tokens
    private function processParentheses(array $tokens): array {
        $result = [];
        $i = 0;
        
        while ($i < count($tokens)) {
            if ($tokens[$i]['type'] === 'paren' && $tokens[$i]['value'] === '(') {
                // Find matching closing paren
                $depth = 1;
                $start = $i + 1;
                $j = $start;
                
                while ($j < count($tokens) && $depth > 0) {
                    if ($tokens[$j]['type'] === 'paren') {
                        if ($tokens[$j]['value'] === '(') {
                            $depth++;
                        } else {
                            $depth--;
                        }
                    }
                    $j++;
                }
                
                // Extract tokens within parentheses
                $innerTokens = array_slice($tokens, $start, $j - $start - 1);
                
                // If the inner tokens form a simple condition, keep them together
                if ($this->isSimpleCondition($innerTokens)) {
                    $result[] = ['type' => 'group', 'value' => $innerTokens, 'simple' => true];
                } else {
                    $innerTree = $this->buildConditionTree($innerTokens);
                    $result[] = ['type' => 'group', 'value' => $innerTree];
                }
                
                $i = $j;
            } else {
                $result[] = $tokens[$i];
                $i++;
            }
        }
        
        return $result;
    }
    
    // Helper method to check if tokens form a simple condition
    private function isSimpleCondition(array $tokens): bool {
        // A simple condition has pattern: condition [operator condition]*
        if (empty($tokens)) return false;
        
        $expectCondition = true;
        foreach ($tokens as $token) {
            if ($expectCondition) {
                if ($token['type'] !== 'condition') return false;
                $expectCondition = false;
            } else {
                if ($token['type'] !== 'operator') return false;
                $expectCondition = true;
            }
        }
        
        return !$expectCondition; // Should end with a condition
    }

    // PHASE 1 IMPROVEMENT: Process logical operators
    private function processOperator(array $tokens, string $operator): array {
        if (count($tokens) <= 1) {
            return $tokens;
        }
        
        // Find the operator
        $opIndex = -1;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if ($tokens[$i]['type'] === 'operator' && $tokens[$i]['value'] === $operator) {
                $opIndex = $i;
                break;
            }
        }
        
        if ($opIndex === -1) {
            // No operator found, return as is
            return count($tokens) === 1 ? $tokens[0] : $tokens;
        }
        
        // Split at operator
        $left = array_slice($tokens, 0, $opIndex);
        $right = array_slice($tokens, $opIndex + 1);
        
        return [
            'type' => 'logical',
            'operator' => $operator,
            'left' => $this->processOperator($left, $operator),
            'right' => $this->processOperator($right, $operator)
        ];
    }

    // PHASE 1 IMPROVEMENT: Format condition tree back to SQL
    private function formatConditionTree($tree, int $depth = 0, bool $isFirst = false): string {
        if (empty($tree)) {
            return '';
        }
        
        $indent = str_repeat($this->indentation, $depth + 1);
        
        if (isset($tree['type'])) {
            switch ($tree['type']) {
                case 'condition':
                    return ($isFirst ? "\n" . $indent : '') . $tree['value'];
                    
                case 'group':
                    // Handle simple grouped conditions differently
                    if (isset($tree['simple']) && $tree['simple']) {
                        $parts = [];
                        foreach ($tree['value'] as $token) {
                            if ($token['type'] === 'condition') {
                                $parts[] = $token['value'];
                            } elseif ($token['type'] === 'operator') {
                                $parts[] = $token['value'];
                            }
                        }
                        return '(' . implode(' ', $parts) . ')';
                    } else {
                        $inner = $this->formatConditionTree($tree['value'], $depth, false);
                        return '(' . $inner . ')';
                    }
                    
                case 'logical':
                    $left = $this->formatConditionTree($tree['left'], $depth, $isFirst);
                    $right = $this->formatConditionTree($tree['right'], $depth, false);
                    
                    // If right side is just a condition (not another logical operation), keep it on same line
                    if (is_array($tree['right']) && isset($tree['right']['type']) && 
                        ($tree['right']['type'] === 'condition' || $tree['right']['type'] === 'group')) {
                        return $left . ' ' . $tree['operator'] . ' ' . $right;
                    }
                    
                    return $left . "\n" . $indent . $tree['operator'] . ' ' . $right;
            }
        }
        
        // Handle array of conditions
        if (is_array($tree) && isset($tree[0])) {
            return $this->formatConditionTree($tree[0], $depth, $isFirst);
        }
        
        return '';
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
    
    // New method to format IN clauses outside of WHERE clause
    private function formatInClausesOutsideWhere(string $sql): string {
        // Only format IN clauses that are NOT within a WHERE clause
        $parts = preg_split('/\bWHERE\b/i', $sql, 2);
        
        if (count($parts) === 1) {
            // No WHERE clause, format all IN clauses
            return $this->formatInClauses($sql);
        }
        
        // Format IN clauses in the part before WHERE
        $beforeWhere = $this->formatInClauses($parts[0]);
        
        // Don't format IN clauses after WHERE - let WHERE handler deal with them
        return $beforeWhere . 'WHERE' . $parts[1];
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
        echo 'Error processing request: ' . $e->getMessage();
    }
}
?>