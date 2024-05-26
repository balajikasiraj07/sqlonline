<?php
// Define the SQLFormatter class
class SQLFormatter {

    public function format($sql) {
        // Simple formatting logic for demonstration
        $formattedSql = preg_replace('/\s+/', ' ', $sql); // Replace multiple spaces with a single space
        $formattedSql = preg_replace('/(SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN|ORDER BY|GROUP BY|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|AS|OR|NOT|NULL|IS|IN|LIKE|BETWEEN|EXISTS|CASE|WHEN|THEN|ELSE|END)\b/i', "\n$1", $formattedSql);
        // Handle comma separately
        $formattedSql = preg_replace('/,/', ",\n\t", $formattedSql);
        // Function to format SQL with tab spaces and new lines
        $formattedSql = preg_replace_callback(
    '/\b(SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN|ORDER BY|GROUP BY|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|AS|AND|OR|NOT|NULL|IS|IN|LIKE|BETWEEN|EXISTS|CASE|WHEN|THEN|ELSE|END)\b/i',
    function ($matches) {
        // List of keywords to be formatted with specific new lines and tab spaces
        $keyword = strtoupper($matches[0]);
        switch ($keyword) {
            case 'SELECT':
            case 'INSERT':
            case 'UPDATE':
            case 'DELETE':
                return "\n$keyword\n\t"; // New line before and tab space after these keywords
            case 'FROM':
            case 'WHERE':
            case 'JOIN':
            case 'LEFT JOIN':
            case 'RIGHT JOIN':
            case 'INNER JOIN':
            case 'OUTER JOIN':
            case 'ORDER BY':
            case 'GROUP BY':
            case 'HAVING':
            case 'LIMIT':
            case 'OFFSET':
            case 'UNION':
                return "$keyword\n\t"; // New line before and tab space after these keywords
            case ',':
                return ",\n\t"; // New line and tab space after commas
            default:
                return " $keyword "; // Single space before and after for other keywords
        }
        
    },
    $formattedSql // Input SQL string to be formatted
);






        return trim($formattedSql);
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

    // Return the formatted query
    echo $formattedQuery;
}
?>
