// auto-breadcrumb.js
class AutoBreadcrumb {
    constructor(options = {}) {
        this.options = {
            container: options.container || '.breadcrumb',
            homeText: options.homeText || 'SQL Formatter',
            homeUrl: options.homeUrl || 'index.html',
            autoDetect: options.autoDetect !== false,
            usePageTitle: options.usePageTitle !== false,
            debugMode: options.debugMode || false,
            ...options
        };
        
        this.init();
    }
    
    init() {
        // Wait for DOM to be fully loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.autoGenerateBreadcrumb());
        } else {
            this.autoGenerateBreadcrumb();
        }
    }
    
    autoGenerateBreadcrumb() {
        const container = document.querySelector(this.options.container);
        if (!container) {
            if (this.options.debugMode) {
                console.warn('Breadcrumb container not found:', this.options.container);
            }
            return;
        }
        
        const breadcrumbData = this.extractBreadcrumbData();
        const html = this.generateHTML(breadcrumbData);
        container.innerHTML = html;
        
        this.addEnhancedFeatures(container);
        
        if (this.options.debugMode) {
            console.log('Breadcrumb generated:', breadcrumbData);
        }
    }
    
    extractBreadcrumbData() {
        const path = window.location.pathname;
        const fileName = path.split('/').pop() || 'index.html';
        
        // Try to get breadcrumb from meta tag first
        const metaBreadcrumb = document.querySelector('meta[name="breadcrumb"]');
        if (metaBreadcrumb) {
            return this.parseMetaBreadcrumb(metaBreadcrumb.content);
        }
        
        // Fallback to intelligent detection from page structure
        return this.generateFromPageStructure(fileName);
    }
    
    parseMetaBreadcrumb(content) {
        // Format: "Home > SQL Tutorials > Current Page"
        const parts = content.split(' > ');
        return parts.map((part, index) => ({
            title: part.trim(),
            url: this.guessUrlFromTitle(part.trim(), index),
            isActive: index === parts.length - 1
        }));
    }
    
    generateFromPageStructure(fileName) {
        const breadcrumbs = [];
        
        // Always start with home (unless we're on home page)
        if (fileName !== 'index.html') {
            breadcrumbs.push({
                title: this.options.homeText,
                url: this.options.homeUrl,
                isActive: false
            });
        }
        
        // Detect page type and add intermediate pages
        if (fileName !== 'index.html') {
            if (this.isBlogPage(fileName)) {
                breadcrumbs.push({
                    title: 'SQL Tutorials',
                    url: 'blog_section.html',
                    isActive: fileName === 'blog_section.html'
                });
            }
            
            // Add current page (if not blog section)
            if (fileName !== 'blog_section.html') {
                breadcrumbs.push({
                    title: this.getCurrentPageTitle(),
                    url: fileName,
                    isActive: true
                });
            }
        } else {
            // We're on home page
            breadcrumbs.push({
                title: this.options.homeText,
                url: this.options.homeUrl,
                isActive: true
            });
        }
        
        return breadcrumbs;
    }
    
    isBlogPage(fileName) {
        // Blog pages and patterns
        const blogPages = [
            'blog_section.html',
            'OrderOfExecutionSQL.html',
            'OptimisedQueryWriting.html',
            'CommonSQLMisconception.html'
        ];
        
        // Also check for patterns
        const blogPatterns = [
            /.*SQL.*\.html$/i,
            /.*blog.*\.html$/i,
            /.*tutorial.*\.html$/i
        ];
        
        return blogPages.includes(fileName) || 
               blogPatterns.some(pattern => pattern.test(fileName));
    }
    
    getCurrentPageTitle() {
        // Try multiple sources for page title (in order of preference)
        
        // 1. Try article title
        const articleTitle = document.querySelector('h1.article-title');
        if (articleTitle) {
            return this.cleanTitle(articleTitle.textContent);
        }
        
        // 2. Try any h1
        const h1 = document.querySelector('h1');
        if (h1) {
            return this.cleanTitle(h1.textContent);
        }
        
        // 3. Try page title (remove site name)
        const title = document.title;
        if (title) {
            const cleanedTitle = title.split('|')[0].split(' - ')[0].trim();
            if (cleanedTitle && cleanedTitle !== document.title) {
                return this.cleanTitle(cleanedTitle);
            }
        }
        
        // 4. Try meta description or og:title
        const ogTitle = document.querySelector('meta[property="og:title"]');
        if (ogTitle) {
            return this.cleanTitle(ogTitle.content);
        }
        
        // 5. Fallback to filename-based title
        return this.generateTitleFromFilename();
    }
    
    cleanTitle(title) {
        return title.trim()
                   .replace(/\s+/g, ' ')  // Multiple spaces to single
                   .substring(0, 60);     // Limit length
    }
    
    generateTitleFromFilename() {
        const path = window.location.pathname;
        const fileName = path.split('/').pop().replace('.html', '');
        
        // Convert camelCase and common patterns to readable titles
        return fileName
            .replace(/([A-Z])/g, ' $1')  // camelCase to spaces
            .replace(/[-_]/g, ' ')       // dashes/underscores to spaces
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
            .trim() || 'Current Page';
    }
    
    guessUrlFromTitle(title, index) {
        if (index === 0 || title.toLowerCase().includes('home') || 
            title.toLowerCase().includes('sql formatter')) {
            return 'index.html';
        }
        if (title.toLowerCase().includes('tutorial') || 
            title.toLowerCase().includes('blog')) {
            return 'blog_section.html';
        }
        return '#';
    }
    
    generateHTML(breadcrumbs) {
        if (breadcrumbs.length === 0) return '';
        
        const items = breadcrumbs.map((item, index) => {
            const position = index + 1;
            
            if (item.isActive) {
                return `
                    <li aria-current="page" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                        <span itemprop="name">${this.escapeHtml(item.title)}</span>
                        <meta itemprop="position" content="${position}" />
                    </li>
                `;
            } else {
                return `
                    <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                        <a href="${item.url}" itemprop="item" data-breadcrumb="${this.escapeHtml(item.title)}">
                            <span itemprop="name">${this.escapeHtml(item.title)}</span>
                        </a>
                        <meta itemprop="position" content="${position}" />
                    </li>
                `;
            }
        }).join('');
        
        return `<ol itemscope itemtype="https://schema.org/BreadcrumbList">${items}</ol>`;
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    addEnhancedFeatures(container) {
        // Add smooth scroll to top when clicking home
        const homeLink = container.querySelector('a[href="index.html"]');
        if (homeLink) {
            homeLink.addEventListener('click', (e) => {
                if (window.location.pathname.includes('index.html') || 
                    window.location.pathname === '/') {
                    e.preventDefault();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
        
        // Add analytics tracking
        this.addAnalyticsTracking(container);
        
        // Add hover effects
        this.addHoverEffects(container);
        
        // Add keyboard navigation
        this.addKeyboardNavigation(container);
        
        // Add loading animation
        this.addLoadingAnimation(container);
    }
    
    addAnalyticsTracking(container) {
        container.addEventListener('click', (e) => {
            const link = e.target.closest('[data-breadcrumb]');
            if (link) {
                const title = link.getAttribute('data-breadcrumb');
                
                // Google Analytics 4
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'breadcrumb_click', {
                        'breadcrumb_title': title,
                        'page_location': window.location.href,
                        'page_title': document.title
                    });
                }
                
                // Google Analytics Universal (legacy)
                if (typeof ga !== 'undefined') {
                    ga('send', 'event', 'Breadcrumb', 'Click', title);
                }
                
                if (this.options.debugMode) {
                    console.log(`Breadcrumb clicked: ${title}`);
                }
            }
        });
    }
    
    addHoverEffects(container) {
        const links = container.querySelectorAll('a');
        links.forEach(link => {
            link.addEventListener('mouseenter', () => {
                link.style.transition = 'transform 0.2s ease';
                link.style.transform = 'translateY(-1px)';
            });
            
            link.addEventListener('mouseleave', () => {
                link.style.transform = 'translateY(0)';
            });
        });
    }
    
    addKeyboardNavigation(container) {
        const links = container.querySelectorAll('a');
        links.forEach((link, index) => {
            link.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' && links[index + 1]) {
                    e.preventDefault();
                    links[index + 1].focus();
                } else if (e.key === 'ArrowLeft' && links[index - 1]) {
                    e.preventDefault();
                    links[index - 1].focus();
                } else if (e.key === 'Home' && links[0]) {
                    e.preventDefault();
                    links[0].focus();
                } else if (e.key === 'End' && links[links.length - 1]) {
                    e.preventDefault();
                    links[links.length - 1].focus();
                }
            });
        });
    }
    
    addLoadingAnimation(container) {
        // Add a subtle fade-in effect
        container.style.opacity = '0';
        container.style.transition = 'opacity 0.3s ease';
        
        setTimeout(() => {
            container.style.opacity = '1';
        }, 100);
    }
    
    // Public method to refresh breadcrumb (useful for SPA)
    refresh() {
        this.autoGenerateBreadcrumb();
    }
    
    // Public method to update options
    updateOptions(newOptions) {
        this.options = { ...this.options, ...newOptions };
        this.refresh();
    }
}

// Auto-initialize if not manually configured
if (typeof window !== 'undefined') {
    // Check if manual initialization is preferred
    if (!window.BREADCRUMB_MANUAL_INIT) {
        document.addEventListener('DOMContentLoaded', () => {
            new AutoBreadcrumb({
                debugMode: false // Set to true for debugging
            });
        });
    }
}