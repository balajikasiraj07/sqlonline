<?php
class SQLFormatter {
    public function format($sql) {
        $formattedSql = preg_replace('/\s+/', ' ', $sql); // Replace multiple spaces with a single space

        // Initial formatting
        $formattedSql = preg_replace_callback(
            '/\b(SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN|GROUP BY|HAVING|LIMIT|OFFSET|UNION|ALL|AS|AND|OR|NOT|NULL|IS|IN|LIKE|BETWEEN|EXISTS|WHEN|THEN|ELSE|END|SUM|COUNT|AVG|OVER|ORDER BY)\b/i',
            function ($matches) {
                $keyword = strtoupper($matches[0]);
                switch ($keyword) {
                    case 'SELECT':
                    case 'INSERT':
                    case 'UPDATE':
                    case 'DELETE':
                    case 'FROM':
                    case 'WHERE':
                        return "\n$keyword\n\t"; // New line before and tab space after these keywords
                    case 'WHERE':
                    case 'JOIN':
                    case 'LEFT JOIN':
                    case 'RIGHT JOIN':
                    case 'INNER JOIN':
                    case 'OUTER JOIN':
                    case 'GROUP BY':
                    case 'HAVING':
                    case 'LIMIT':
                    case 'OFFSET':
                    case 'UNION':
                        return "\n$keyword\n\t"; // New line before and tab space after these keywords
                    case 'WHEN':
                    case 'ELSE':
                        return "\n\t$keyword"; // New line and tab before these keywords
                    default:
                        return " $keyword "; // Single space before and after for other keywords
                }
            },
            $formattedSql // Input SQL string to be formatted
        );

        // Handle commas separately if not inside specific functions
        $formattedSql = $this->handleCommas($formattedSql);

        // Apply the nested SELECT indentation logic
        $formattedSql = $this->indentNestedSelects($formattedSql);

        // Apply the nested SELECT indentation logic
        $formattedSql = $this->uppercaseKeywords($formattedSql);

        return trim($formattedSql);
    }




    private function handleCommas($sql) {
        $formattedQuery = '';
        $insideFunction = false;
        $functionStack = [];

        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if ($char === '(' && $i > 0) {
                $prefix = substr($sql, 0, $i);
                if (preg_match('/\b(count|sum|avg|min|max|over|concat|coalesce)\s*$/i', $prefix)) {
                    $insideFunction = true;
                    $functionStack[] = $prefix;
                }
            }

            if ($char === ')') {
                if ($insideFunction && !empty($functionStack)) {
                    array_pop($functionStack);
                    if (empty($functionStack)) {
                        $insideFunction = false;
                    }
                }
            }

            if ($char === ',' && !$insideFunction) {
                $formattedQuery .= ",\n\t";
            } else {
                $formattedQuery .= $char;
            }
        }

        return $formattedQuery;
    }




    private function indentNestedSelects($sql) {
        $lines = explode("\n", $sql);
        $indentLevel = 0;
        $formattedLines = [];
        $additionalIndentation = "\t"; // 1 tab space
        $halfIndentation = "    "; // 0.5 tab space (4 spaces assuming a tab is 8 spaces)
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Accumulate the number of opening and closing parentheses
            $openingParentheses = substr_count($trimmedLine, '(');
            $closingParentheses = substr_count($trimmedLine, ')');
    
            // Adjust indent level based on parentheses count
            $indentLevel += $openingParentheses;
            $indentLevel -= $closingParentheses;
    
            // Ensure indent level doesn't go negative
            $indentLevel = max($indentLevel, 0);
            
            // Apply indentation
            if (stripos($trimmedLine, 'SELECT') === 0) {
                $formattedLines[] = str_repeat($additionalIndentation, $indentLevel) . $trimmedLine;
            } else {
                if ($indentLevel > 0) {
                    $formattedLines[] = str_repeat($additionalIndentation, $indentLevel - 1) . $halfIndentation . $trimmedLine;
                } else {
                    $formattedLines[] = $trimmedLine;
                }
            }
        }
        
        return implode("\n", $formattedLines);
    }

    public function uppercaseKeywords($sql) {
        // List of keywords to be uppercased
        $keywords = [
            'SELECT', 'FROM', 'WHERE', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 
            'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'OUTER JOIN', 'GROUP BY', 
            'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'ALL', 'AS', 'AND', 'OR', 'NOT', 'NULL', 
            'IS', 'IN', 'LIKE', 'BETWEEN', 'EXISTS', 'WHEN', 'THEN', 'ELSE', 'END', 
            'SUM', 'COUNT', 'AVG', 'OVER', 'ORDER BY', 'RANK', 'LEAD', 'LAG' ,'coalesce','max','min','on','CASE'
        ];

        // Regular expression pattern to match keywords
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $keywords)) . ')\b/i';

        // Callback function to convert matched keywords to uppercase
        $callback = function ($matches) {
            return strtoupper($matches[0]);
        };

        // Perform the replacement
        $uppercasedSql = preg_replace_callback($pattern, $callback, $sql);

        return $uppercasedSql;
    }

    
    

    
}

// Handle the POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the input SQL query from the POST request
    $inputQuery = $_POST['input-query'];

    // Create an instance of the SQLFormatter class
    $formatter = new SQLFormatter();

    // Format the input query
    $formattedQuery = $formatter->format($inputQuery);

    // Return the formatted query without any HTML encoding
    header('Content-Type: text/plain; charset=UTF-8');
    echo $formattedQuery;
}
?>
