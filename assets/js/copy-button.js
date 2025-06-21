        // Copy to clipboard function
        function copyToClipboard() {
          const output = document.getElementById('sqlOutput');
          if (output.value.trim()) {
              output.select();
              document.execCommand('copy');
              
              // Visual feedback
              const btn = event.target;
              const originalText = btn.textContent;
              btn.textContent = 'Copied!';
              btn.style.background = '#10b981';
              
              setTimeout(() => {
                  btn.textContent = originalText;
                  btn.style.background = '#2563eb';
              }, 2000);
          }
      }
