/**
 * Dynamic Related Articles Generator
 * Automatically generates related articles section for blog pages
 */

class RelatedArticlesManager {
    constructor() {
        this.blogData = null;
        this.currentPageUrl = this.getCurrentPageUrl();
        this.init();
    }

    // Get current page URL (handles both relative and absolute paths)
    getCurrentPageUrl() {
        const path = window.location.pathname;
        const filename = path.split('/').pop();
        return filename || path;
    }

    // Initialize the related articles system
    async init() {
        try {
            await this.loadBlogData();
            this.generateRelatedArticles();
        } catch (error) {
            console.warn('Related articles could not be loaded:', error);
            this.generateFallbackContent();
        }
    }

    // Load blog metadata from JSON file
    async loadBlogData() {
        try {
            const response = await fetch('assets/data/blog-metadata.json');
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            this.blogData = await response.json();
        } catch (error) {
            // Fallback: try relative path
            const response = await fetch('./assets/data/blog-metadata.json');
            if (!response.ok) {
                throw new Error(`Could not load blog metadata: ${error.message}`);
            }
            this.blogData = await response.json();
        }
    }

    // Find current blog in metadata
    getCurrentBlog() {
        if (!this.blogData || !this.blogData.blogs) return null;
        
        return this.blogData.blogs.find(blog => 
            blog.url === this.currentPageUrl || 
            blog.url.includes(this.currentPageUrl) ||
            this.currentPageUrl.includes(blog.url.replace('.html', ''))
        );
    }

    // Get related articles (excluding current page)
    getRelatedArticles() {
        if (!this.blogData || !this.blogData.blogs) return [];

        const currentBlog = this.getCurrentBlog();
        let relatedArticles = this.blogData.blogs.filter(blog => 
            blog.url !== this.currentPageUrl && 
            (!currentBlog || blog.id !== currentBlog.id)
        );

        // If we have a current blog, prioritize same category
        if (currentBlog) {
            relatedArticles.sort((a, b) => {
                const aMatchesCategory = a.category === currentBlog.category ? 1 : 0;
                const bMatchesCategory = b.category === currentBlog.category ? 1 : 0;
                return bMatchesCategory - aMatchesCategory;
            });
        }

        // Ensure we have at least 3 articles (add fallback if needed)
        while (relatedArticles.length < 3 && this.blogData.fallbackArticle) {
            relatedArticles.push({
                ...this.blogData.fallbackArticle,
                id: `fallback-${relatedArticles.length}`
            });
        }

        return relatedArticles.slice(0, 3); // Return max 3 articles
    }

    // Generate HTML for a single related article card
    generateArticleCard(article) {
        const isClickable = article.url && article.url !== '#';
        const titleContent = isClickable 
            ? `<a href="${article.url}">${article.title}</a>`
            : article.title;

        return `
            <article class="related-card" ${isClickable ? `onclick="window.location.href='${article.url}'"` : ''}>
                <div class="related-meta">
                    <span class="related-category ${article.categoryClass || ''}">${article.category}</span>
                    <span class="related-time">${article.readTime}</span>
                </div>
                <h4>${titleContent}</h4>
                <div class="related-footer">
                    <span class="related-views">👀 ${article.views}</span>
                    <span class="related-arrow">→</span>
                </div>
            </article>
        `;
    }

    // Generate complete related articles section
    generateRelatedArticles() {
        const container = document.getElementById('related-articles-container');
        if (!container) {
            console.warn('Related articles container not found');
            return;
        }

        const relatedArticles = this.getRelatedArticles();
        
        if (relatedArticles.length === 0) {
            this.generateFallbackContent();
            return;
        }

        const articlesHTML = relatedArticles.map(article => 
            this.generateArticleCard(article)
        ).join('');

        container.innerHTML = `
            <div class="related-articles">
                <div class="related-header">
                    <h2>More by Balaji</h2>
                    <p>Continue your SQL learning journey with these hand-picked articles</p>
                </div>
                <div class="related-grid">
                    ${articlesHTML}
                </div>
                <div class="related-cta">
                    <a href="https://sqlonline.in#blog" class="view-all-btn">
                        <span>View All Articles</span>
                        <span class="btn-icon">📚</span>
                    </a>
                </div>
            </div>
        `;
    }

    // Generate fallback content when data loading fails
    generateFallbackContent() {
        const container = document.getElementById('related-articles-container');
        if (!container) return;

        container.innerHTML = `
            <div class="related-articles">
                <div class="related-header">
                    <h2>More by Balaji</h2>
                    <p>Continue your SQL learning journey</p>
                </div>
                <div class="related-cta">
                    <a href="https://sqlonline.in#blog" class="view-all-btn">
                        <span>View All Articles</span>
                        <span class="btn-icon">📚</span>
                    </a>
                </div>
            </div>
        `;
    }

    // Public method to update blog data (for future use)
    async updateBlogData() {
        await this.loadBlogData();
        this.generateRelatedArticles();
    }

    // Public method to add new blog article
    addBlogArticle(blogData) {
        if (!this.blogData) this.blogData = { blogs: [] };
        if (!this.blogData.blogs) this.blogData.blogs = [];
        
        this.blogData.blogs.push(blogData);
        this.generateRelatedArticles();
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure all elements are rendered
    setTimeout(() => {
        window.relatedArticlesManager = new RelatedArticlesManager();
    }, 100);
});

// Expose for manual initialization if needed
window.RelatedArticlesManager = RelatedArticlesManager;