{assign var=ch247Copy_companyName value=$companyName|escape:'html'}
{assign var=ch247Copy_effectiveDate value=$effectiveDate|escape:'html'}
{assign var=ch247Copy_jurisdiction value=$jurisdiction|escape:'html'}
{* Terms & Conditions Template *}
{* Template: templates/hostx/termsofservice.tpl *}
{* Compatible with WHMCS 8.x+ *}

<style>
/* Terms & Conditions Page Styles */
.terms-page {
    --terms-primary: #1e3a8a;
    --terms-secondary: #3b82f6;
    --terms-accent: #0ea5e9;
    --terms-bg: #f8fafc;
    --terms-card-bg: #ffffff;
    --terms-text: #1e293b;
    --terms-text-muted: #64748b;
    --terms-border: #e2e8f0;
    --terms-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    --terms-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--terms-text);
    background: var(--terms-bg);
    min-height: 100vh;
}

/* Hero Banner */
.terms-hero {
    background: linear-gradient(135deg, var(--terms-primary) 0%, var(--terms-secondary) 50%, var(--terms-accent) 100%);
    padding: 80px 0 100px;
    position: relative;
    overflow: hidden;
}

.terms-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 600px;
    height: 600px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
}

.terms-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 400px;
    height: 400px;
    background: rgba(255, 255, 255, 0.02);
    border-radius: 50%;
}

.terms-hero-content {
    position: relative;
    z-index: 2;
    text-align: center;
    color: #fff;
}

.terms-hero-content h1 {
    font-size: 2.75rem;
    font-weight: 700;
    margin: 0 0 12px;
    letter-spacing: -0.02em;
}

.terms-hero-content .effective-date {
    font-size: 1rem;
    opacity: 0.85;
    font-weight: 400;
}

/* Main Content Container */
.terms-container {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 24px;
}

/* Content Card */
.terms-card {
    background: var(--terms-card-bg);
    border-radius: 16px;
    box-shadow: var(--terms-shadow);
    margin-top: -40px;
    position: relative;
    z-index: 10;
    padding: 48px;
    border: 1px solid var(--terms-border);
}

/* Section Styling */
.terms-section {
    margin-bottom: 48px;
}

.terms-section:last-child {
    margin-bottom: 0;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--terms-border);
}

.section-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, var(--terms-primary), var(--terms-secondary));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.section-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--terms-primary);
    margin: 0;
    line-height: 1.3;
}

.section-content {
    padding-left: 58px;
}

.section-content p {
    font-size: 1rem;
    line-height: 1.8;
    color: var(--terms-text);
    margin: 0 0 16px;
    text-align: justify;
}

.section-content p:last-child {
    margin-bottom: 0;
}

/* Introduction special styling */
.terms-intro {
    background: linear-gradient(135deg, rgba(30, 58, 138, 0.03) 0%, rgba(59, 130, 246, 0.03) 100%);
    border-left: 4px solid var(--terms-secondary);
    border-radius: 12px;
    padding: 28px 32px;
    margin-bottom: 48px;
}

.terms-intro p {
    font-size: 1.05rem;
    line-height: 1.9;
    margin: 0 0 14px;
    text-align: justify;
}

.terms-intro p:last-child {
    margin-bottom: 0;
}

/* Contact Section Special */
.contact-block {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-top: 24px;
}

.contact-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 20px;
    background: var(--terms-bg);
    border-radius: 12px;
    border: 1px solid var(--terms-border);
    transition: all 0.3s ease;
}

.contact-item:hover {
    box-shadow: var(--terms-shadow-hover);
    transform: translateY(-2px);
}

.contact-item i {
    font-size: 1.2rem;
    color: var(--terms-secondary);
    margin-top: 2px;
}

.contact-item .contact-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--terms-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
}

.contact-item .contact-value {
    font-size: 1rem;
    color: var(--terms-text);
    font-weight: 500;
    word-break: break-word;
}

.contact-item a {
    color: var(--terms-secondary);
    text-decoration: none;
    transition: color 0.2s;
}

.contact-item a:hover {
    color: var(--terms-primary);
    text-decoration: underline;
}

/* Last Updated Footer */
.terms-footer-meta {
    margin-top: 48px;
    padding-top: 28px;
    border-top: 1px solid var(--terms-border);
    text-align: center;
}

.terms-footer-meta p {
    font-size: 0.9rem;
    color: var(--terms-text-muted);
    margin: 0;
}

.terms-footer-meta .jurisdiction {
    margin-top: 8px;
    font-weight: 500;
    color: var(--terms-text);
}

/* Responsive Design */
@media (max-width: 768px) {
    .terms-hero {
        padding: 60px 0 80px;
    }

    .terms-hero-content h1 {
        font-size: 2rem;
    }

    .terms-card {
        margin-top: -30px;
        padding: 28px;
        border-radius: 12px;
    }

    .section-header {
        gap: 12px;
        margin-bottom: 18px;
        padding-bottom: 16px;
    }

    .section-icon {
        width: 38px;
        height: 38px;
        font-size: 0.95rem;
        border-radius: 10px;
    }

    .section-header h2 {
        font-size: 1.2rem;
    }

    .section-content {
        padding-left: 0;
    }

    .terms-intro {
        padding: 20px 24px;
        margin-bottom: 36px;
    }

    .terms-section {
        margin-bottom: 36px;
    }

    .contact-block {
        grid-template-columns: 1fr;
        gap: 14px;
    }
}

@media (max-width: 480px) {
    .terms-hero-content h1 {
        font-size: 1.65rem;
    }

    .terms-card {
        padding: 22px;
    }

    .terms-intro {
        padding: 18px 20px;
    }

    .section-content p,
    .terms-intro p {
        font-size: 0.95rem;
    }
}

/* Print Styles */
@media print {
    .terms-hero {
        background: var(--terms-primary) !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .terms-card {
        box-shadow: none;
        border: 1px solid #ccc;
    }

    .section-icon {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<div class="terms-page">
    <!-- Hero Banner Section -->
    <section class="terms-hero">
        <div class="terms-container">
            <div class="terms-hero-content">
                <h1>{$pageTitle}</h1>
                <p class="effective-date">
                    {$LANG.ch247_termsofservice_64b0077cd7|replace:'{effectiveDate}':$ch247Copy_effectiveDate}
                </p>
            </div>
        </div>
    </section>

    <!-- Main Content Card -->
    <div class="terms-container">
        <div class="terms-card">
            
            <!-- Introduction -->
            <div class="terms-intro">
                <p>{$LANG.ch247_termsofservice_9ef524fa29|replace:'{companyName}':$ch247Copy_companyName}</p>
                <p>{$LANG.ch247_termsofservice_c71fa764dc}</p>
            </div>

            <!-- Section: Account Terms -->
            <section class="terms-section" id="account-terms">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_7d74e1af23}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_0ab67f3093}</p>
                    <p>{$LANG.ch247_termsofservice_0a601b480c}</p>
                    <p>{$LANG.ch247_termsofservice_771a63579e}</p>
                </div>
            </section>

            <!-- Section: Service Usage Terms -->
            <section class="terms-section" id="service-usage">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-server"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_56a6c979bf}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_e890ebecb9}</p>
                    <p>{$LANG.ch247_termsofservice_e8da729df1|replace:'{companyName}':$ch247Copy_companyName}</p>
                    <p>{$LANG.ch247_termsofservice_24c122157c}</p>
                </div>
            </section>

            <!-- Section: Payments & Billing Terms -->
            <section class="terms-section" id="payments-billing">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_6271937a00}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_2b04f0a36a}</p>
                    <p>{$LANG.ch247_termsofservice_524e9fa0f0}</p>
                    <p>{$LANG.ch247_termsofservice_97390408e6}</p>
                </div>
            </section>

            <!-- Section: User Responsibilities -->
            <section class="terms-section" id="user-responsibilities">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_1920807708}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_4fae030045}</p>
                    <p>{$LANG.ch247_termsofservice_9937b3441f|replace:'{companyName}':$ch247Copy_companyName}</p>
                    <p>{$LANG.ch247_termsofservice_64206b821e}</p>
                </div>
            </section>

            <!-- Section: Limitations of Liability -->
            <section class="terms-section" id="limitations-liability">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_21ca8411a7}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_5e1f5e59ff|replace:'{companyName}':$ch247Copy_companyName}</p>
                    <p>{$LANG.ch247_termsofservice_4b8513d8fa}</p>
                </div>
            </section>

            <!-- Section: Termination Policy -->
            <section class="terms-section" id="termination">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_f5dfacb9af}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_05bcf24d10}</p>
                    <p>{$LANG.ch247_termsofservice_5625f521f0}</p>
                    <p>{$LANG.ch247_termsofservice_9cda8b6d94}</p>
                </div>
            </section>

            <!-- Section: Contact Information -->
            <section class="terms-section" id="contact">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h2>{$LANG.ch247_termsofservice_7c01aec3b1}</h2>
                </div>
                <div class="section-content">
                    <p>{$LANG.ch247_termsofservice_3b8656d6cb}</p>
                    
                    <div class="contact-block">
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <div class="contact-label">{$LANG.ch247_termsofservice_79bed99f1f}</div>
                                <div class="contact-value">
                                    <a href="mailto:{$supportEmail}">{$supportEmail}</a>
                                </div>
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-globe"></i>
                            <div>
                                <div class="contact-label">{$LANG.ch247_termsofservice_b5a229ac8b}</div>
                                <div class="contact-value">
                                    <a href="{$companyUrl}" target="_blank" rel="noopener">www.cloudhost247.com</a>
                                </div>
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-gavel"></i>
                            <div>
                                <div class="contact-label">{$LANG.ch247_termsofservice_fa3141a378}</div>
                                <div class="contact-value">{$jurisdiction}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer Meta -->
            <div class="terms-footer-meta">
                <p>{$LANG.ch247_termsofservice_a7f67428c4|replace:'{effectiveDate}':$ch247Copy_effectiveDate}</p>
                <p class="jurisdiction">{$LANG.ch247_termsofservice_a53536db4f|replace:'{jurisdiction}':$ch247Copy_jurisdiction}</p>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scroll for any anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Add subtle animation on scroll
    var sections = document.querySelectorAll('.terms-section');
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    sections.forEach(function(section) {
        section.style.opacity = '0';
        section.style.transform = 'translateY(20px)';
        section.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(section);
    });
});
</script>
