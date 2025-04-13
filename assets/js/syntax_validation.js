document.addEventListener('DOMContentLoaded', function() {
    // Add click handler for validate button
    const validateButton = document.getElementById('validate-button');
    const inputArea = document.getElementById('input-area');
    const outputArea = document.getElementById('output-area');
    
    if (validateButton && inputArea && outputArea) {
      validateButton.addEventListener('click', function() {
        try {
          const sql = inputArea.value;
          
          if (!sql || !sql.trim()) {
            outputArea.value = "Please enter SQL code to validate.";
            return;
          }
          
          const errors = validateSQL(sql);
          
          if (errors.length === 0) {
            outputArea.value = "✅ No syntax issues detected in your SQL.";
          } else {
            let errorMessage = "⚠️ SQL Validation Found " + errors.length + " potential issue(s):\n\n";
            
            errors.forEach(function(error, index) {
              errorMessage += (index + 1) + ". " + error.type + ": " + error.message + "\n";
            });
            
            // Add a helpful note
            errorMessage += "\n(Keep in mind that this is a basic validator and might not catch all issues)";
            
            outputArea.value = errorMessage;
          }
        } catch (e) {
          console.error("Validation error:", e);
          outputArea.value = "An error occurred during validation. Please try again.";
        }
      });
    }
    
    // SQL Validation function with improved parentheses handling
    function validateSQL(sql) {
      if (!sql || typeof sql !== 'string') return [];
      
      const errors = [];
      
      try {
        // Check for unclosed quotes
        let inQuote = false;
        let quoteStartPos = -1;
        let inEscapeSequence = false;
        
        for (let i = 0; i < sql.length; i++) {
          if (sql[i] === '\\' && !inEscapeSequence) {
            inEscapeSequence = true;
            continue;
          }
          
          if (inEscapeSequence) {
            inEscapeSequence = false;
            continue;
          }
          
          if (sql[i] === "'") {
            if (!inQuote) {
              inQuote = true;
              quoteStartPos = i;
            } else {
              inQuote = false;
            }
          }
        }
        
        if (inQuote) {
          const lineNumber = (sql.substr(0, quoteStartPos).match(/\n/g) || []).length + 1;
          const startLine = sql.substr(0, quoteStartPos).lastIndexOf('\n');
          const position = quoteStartPos - (startLine === -1 ? 0 : startLine) + 1;
          
          errors.push({
            type: "Unclosed quote",
            message: "You have an unclosed single quote starting at line " + lineNumber + ", position " + position
          });
        }
        
        // Enhanced parentheses checking
        const openStack = [];        // Stack to track opening parentheses
        const closePositions = [];   // Array to track closing parentheses without matches
        let inString = false;        // Flag to track if we're inside a string
        
        for (let i = 0; i < sql.length; i++) {
          // Skip content inside strings
          if (sql[i] === "'" && (i === 0 || sql[i-1] !== '\\')) {
            inString = !inString;
            continue;
          }
          
          if (inString) continue; // Skip everything inside strings
          
          if (sql[i] === '(') {
            openStack.push({
              position: i,
              line: (sql.substr(0, i).match(/\n/g) || []).length + 1,
              column: i - (sql.substr(0, i).lastIndexOf('\n') === -1 ? 0 : sql.substr(0, i).lastIndexOf('\n'))
            });
          } else if (sql[i] === ')') {
            if (openStack.length === 0) {
              // Unmatched closing parenthesis
              closePositions.push({
                position: i,
                line: (sql.substr(0, i).match(/\n/g) || []).length + 1,
                column: i - (sql.substr(0, i).lastIndexOf('\n') === -1 ? 0 : sql.substr(0, i).lastIndexOf('\n'))
              });
            } else {
              // Matched with opening parenthesis
              openStack.pop();
            }
          }
        }
        
        // Report all unclosed opening parentheses
        if (openStack.length > 0) {
          // Report each unclosed parenthesis individually with location
          openStack.forEach((paren, index) => {
            errors.push({
              type: "Unclosed parenthesis",
              message: `Unclosed opening parenthesis #${index+1} at line ${paren.line}, position ${paren.column}`
            });
          });
          
          // Add a summary message if there are many
          if (openStack.length > 1) {
            errors.push({
              type: "Multiple unclosed parentheses",
              message: `You have ${openStack.length} unclosed opening parentheses in total`
            });
          }
        }
        
        // Report all unmatched closing parentheses
        if (closePositions.length > 0) {
          // Report each unmatched closing parenthesis individually
          closePositions.forEach((paren, index) => {
            errors.push({
              type: "Unmatched closing parenthesis",
              message: `Unmatched closing parenthesis #${index+1} at line ${paren.line}, position ${paren.column}`
            });
          });
          
          // Add a summary message if there are many
          if (closePositions.length > 1) {
            errors.push({
              type: "Multiple unmatched parentheses",
              message: `You have ${closePositions.length} unmatched closing parentheses in total`
            });
          }
        }
        
        // Extra check for parentheses balance
        if (openStack.length > 0 && closePositions.length > 0) {
          errors.push({
            type: "Parentheses mismatch",
            message: `You have both unclosed opening parentheses (${openStack.length}) and unmatched closing parentheses (${closePositions.length})`
          });
        }
        
        // Check for JOIN without ON or USING
        try {
          const sqlLower = sql.toLowerCase();
          const joinRegex = /\b(inner|left|right|full|cross)?\s+join\s+([`\[\w\].]+|"[^"]+")/gi;
          let match;
          
          const joins = [];
          while ((match = joinRegex.exec(sqlLower)) !== null) {
            // Skip CROSS JOIN as it doesn't need ON/USING
            if (match[0].toLowerCase().includes("cross join")) {
              continue;
            }
            
            joins.push({
              position: match.index,
              length: match[0].length,
              text: match[0]
            });
          }
          
          // Check each join for ON or USING
          for (let i = 0; i < joins.length; i++) {
            const join = joins[i];
            const joinEndPos = join.position + join.length;
            
            // Search limit is the next JOIN or end of string
            const searchLimit = (i < joins.length - 1) ? joins[i + 1].position : sql.length;
            
            const segmentToCheck = sqlLower.substring(joinEndPos, searchLimit);
            const hasOn = / on\s+/i.test(segmentToCheck);
            const hasUsing = / using\s*\(/i.test(segmentToCheck);
            
            if (!hasOn && !hasUsing) {
              const lineNumber = (sql.substr(0, join.position).match(/\n/g) || []).length + 1;
              const startLine = sql.substr(0, join.position).lastIndexOf('\n');
              const position = join.position - (startLine === -1 ? 0 : startLine) + 1;
              
              errors.push({
                type: "JOIN without conditions",
                message: "You have a JOIN without a corresponding ON or USING clause at line " + lineNumber + ", position " + position
              });
            }
          }
        } catch (e) {
          console.error("JOIN check error:", e);
        }
        
      } catch (e) {
        console.error("Validation error:", e);
        errors.push({
          type: "Validation error",
          message: "An error occurred during validation"
        });
      }
      
      return errors;
    }
  });