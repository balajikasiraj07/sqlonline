// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
  // Get reference to the copy button
  const copyButton = document.getElementById('copy-button');
  
  // Make sure the button exists on the page
  if (copyButton) {
    // Add click event listener
    copyButton.addEventListener('click', function() {
      // Get the formatted SQL from the output textarea
      const outputArea = document.getElementById('output-area');
      const sql = outputArea.value;
      
      // Check if there's content to copy
      if (!sql || !sql.trim()) {
        alert('No formatted SQL to copy!');
        return;
      }
      
      // Use a simple, reliable approach for copying
      const tempTextarea = document.createElement('textarea');
      tempTextarea.value = sql;
      document.body.appendChild(tempTextarea);
      tempTextarea.select();
      
      try {
        // Execute the copy command
        document.execCommand('copy');
        
        // Visual feedback
        const originalText = copyButton.textContent;
        copyButton.textContent = 'Copied!';
        
        // Reset button text after 2 seconds
        setTimeout(function() {
          copyButton.textContent = originalText;
        }, 2000);
      } catch (err) {
        console.error('Copy failed:', err);
        alert('Could not copy text. Please try again.');
      } finally {
        // Clean up
        document.body.removeChild(tempTextarea);
      }
    });
  }
});