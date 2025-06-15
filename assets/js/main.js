/*
	Simplified main.js for SQL Formatter
	Removed jQuery dependencies and unnecessary features
*/

(function() {
	'use strict';

	// Wait for DOM to be ready
	document.addEventListener('DOMContentLoaded', function() {
		
		// Remove preload class after page loads
		window.addEventListener('load', function() {
			setTimeout(function() {
				document.body.classList.remove('is-preload');
			}, 100);
		});

		// Handle window resize events with debouncing
		let resizeTimeout;
		window.addEventListener('resize', function() {
			// Mark as resizing
			document.body.classList.add('is-resizing');

			// Remove after delay
			clearTimeout(resizeTimeout);
			resizeTimeout = setTimeout(function() {
				document.body.classList.remove('is-resizing');
			}, 100);
		});

		// Mobile menu functionality (if needed)
		const menuToggle = document.querySelector('.menu-toggle');
		const navigation = document.querySelector('.navigation');
		
		if (menuToggle && navigation) {
			menuToggle.addEventListener('click', function(e) {
				e.preventDefault();
				navigation.classList.toggle('active');
			});
		}

		// Smooth scroll for anchor links (if any)
		document.querySelectorAll('a[href^="#"]').forEach(anchor => {
			anchor.addEventListener('click', function(e) {
				const targetId = this.getAttribute('href');
				if (targetId === '#') return;
				
				const targetElement = document.querySelector(targetId);
				if (targetElement) {
					e.preventDefault();
					targetElement.scrollIntoView({
						behavior: 'smooth',
						block: 'start'
					});
				}
			});
		});

		// Focus on input textarea when page loads
		const inputArea = document.getElementById('input-area');
		if (inputArea) {
			inputArea.focus();
		}

		// Add active class to current navigation item (if you have navigation)
		const currentLocation = location.pathname;
		const navLinks = document.querySelectorAll('nav a');
		navLinks.forEach(link => {
			if (link.getAttribute('href') === currentLocation) {
				link.classList.add('active');
			}
		});

		// Handle escape key to clear textareas
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				const activeElement = document.activeElement;
				if (activeElement && activeElement.tagName === 'TEXTAREA') {
					// Optional: Ask for confirmation before clearing
					if (activeElement.value && activeElement.value.trim()) {
						if (confirm('Clear the current text?')) {
							activeElement.value = '';
							activeElement.focus();
						}
					}
				}
			}
		});

		// Auto-resize textareas (optional enhancement)
		const textareas = document.querySelectorAll('textarea');
		textareas.forEach(textarea => {
			// Set initial height
			adjustTextareaHeight(textarea);
			
			// Adjust on input
			textarea.addEventListener('input', function() {
				adjustTextareaHeight(this);
			});
		});

		function adjustTextareaHeight(textarea) {
			// Reset height to auto to get the correct scrollHeight
			textarea.style.height = 'auto';
			// Set the height to match content (with a max height)
			const maxHeight = 500; // Maximum height in pixels
			const newHeight = Math.min(textarea.scrollHeight, maxHeight);
			textarea.style.height = newHeight + 'px';
		}

		// Simple notification system for copy success (works with copy-button.js)
		window.showNotification = function(message, type = 'success') {
			const notification = document.createElement('div');
			notification.className = `notification ${type}`;
			notification.textContent = message;
			document.body.appendChild(notification);

			// Show notification
			setTimeout(() => notification.classList.add('show'), 100);

			// Hide and remove after 3 seconds
			setTimeout(() => {
				notification.classList.remove('show');
				setTimeout(() => notification.remove(), 300);
			}, 3000);
		};

		// Performance: Lazy load images if any
		const images = document.querySelectorAll('img[data-src]');
		if ('IntersectionObserver' in window) {
			const imageObserver = new IntersectionObserver((entries, observer) => {
				entries.forEach(entry => {
					if (entry.isIntersecting) {
						const img = entry.target;
						img.src = img.dataset.src;
						img.removeAttribute('data-src');
						imageObserver.unobserve(img);
					}
				});
			});

			images.forEach(img => imageObserver.observe(img));
		} else {
			// Fallback for older browsers
			images.forEach(img => {
				img.src = img.dataset.src;
				img.removeAttribute('data-src');
			});
		}

		// Print functionality for formatted SQL
		window.printFormattedSQL = function() {
			const outputArea = document.getElementById('output-area');
			if (outputArea && outputArea.value) {
				const printWindow = window.open('', '_blank');
				printWindow.document.write(`
					<html>
						<head>
							<title>Formatted SQL Query</title>
							<style>
								body { font-family: 'Courier New', monospace; white-space: pre-wrap; padding: 20px; }
							</style>
						</head>
						<body>${outputArea.value}</body>
					</html>
				`);
				printWindow.document.close();
				printWindow.print();
			}
		};

		// Keyboard shortcuts
		document.addEventListener('keydown', function(e) {
			// Ctrl/Cmd + Enter to format
			if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
				e.preventDefault();
				const formatButton = document.getElementById('convert-button');
				if (formatButton) formatButton.click();
			}
			
			// Ctrl/Cmd + Shift + C to copy
			if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'C') {
				e.preventDefault();
				const copyButton = document.getElementById('copy-button');
				if (copyButton) copyButton.click();
			}
		});

		// Theme toggle (if you want to add dark mode)
		const themeToggle = document.querySelector('.theme-toggle');
		if (themeToggle) {
			// Check for saved theme preference or default to 'light'
			const currentTheme = localStorage.getItem('theme') || 'light';
			document.documentElement.setAttribute('data-theme', currentTheme);

			themeToggle.addEventListener('click', function() {
				const theme = document.documentElement.getAttribute('data-theme');
				const newTheme = theme === 'light' ? 'dark' : 'light';
				
				document.documentElement.setAttribute('data-theme', newTheme);
				localStorage.setItem('theme', newTheme);
			});
		}

		// Console message
		console.log('%cSQL Formatter Online', 'color: #f56a6a; font-size: 20px; font-weight: bold;');
		console.log('Format your SQL queries with ease!');
	});

})();