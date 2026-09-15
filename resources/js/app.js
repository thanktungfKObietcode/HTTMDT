const onReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
        return;
    }

    callback();
};

const setOpen = (element, open) => {
    if (!element) {
        return;
    }

    element.hidden = !open;
    element.classList.toggle('is-open', open);
};

onReady(() => {
    const accountTrigger = document.querySelector('[data-account-menu-trigger]');
    const accountMenu = document.getElementById('accountMenu');
    const searchTrigger = document.querySelector('[data-search-toggle]');
    const searchPanel = document.querySelector('[data-search-panel]');
    const megaTrigger = document.querySelector('[data-mega-trigger]');
    const megaMenu = document.querySelector('[data-mega-menu]');
    const megaGroup = document.querySelector('[data-mega-group]');
    const mobileTrigger = document.querySelector('[data-mobile-menu-toggle]');
    const mobileDrawer = document.querySelector('[data-mobile-drawer]');
    const mobileBackdrop = document.querySelector('[data-mobile-backdrop]');
    const mobileClose = document.querySelector('[data-mobile-menu-close]');

    const setDisclosure = (trigger, panel, open, focusTarget = null) => {
        if (!trigger || !panel) {
            return;
        }

        setOpen(panel, open);
        trigger.setAttribute('aria-expanded', String(open));
        panel.setAttribute('aria-hidden', String(!open));

        if (open && focusTarget) {
            requestAnimationFrame(() => focusTarget.focus());
        }
    };

    let megaCloseTimer = null;
    const clearMegaCloseTimer = () => {
        if (megaCloseTimer !== null) {
            window.clearTimeout(megaCloseTimer);
            megaCloseTimer = null;
        }
    };
    const setMegaOpen = (open) => {
        clearMegaCloseTimer();
        setDisclosure(megaTrigger, megaMenu, open);
    };
    const scheduleMegaClose = () => {
        clearMegaCloseTimer();
        megaCloseTimer = window.setTimeout(() => setMegaOpen(false), 160);
    };

    const closeTransientPanels = (except = null) => {
        if (except !== accountMenu) setDisclosure(accountTrigger, accountMenu, false);
        if (except !== searchPanel) setDisclosure(searchTrigger, searchPanel, false);
        if (except !== megaMenu) setMegaOpen(false);
    };

    const closeDrawer = (restoreFocus = false) => {
        if (!mobileTrigger || !mobileDrawer || !mobileBackdrop) {
            return;
        }

        setDisclosure(mobileTrigger, mobileDrawer, false);
        setOpen(mobileBackdrop, false);
        document.body.classList.remove('drawer-open');

        if (restoreFocus) {
            mobileTrigger.focus();
        }
    };

    if (accountTrigger && accountMenu) {
        setDisclosure(accountTrigger, accountMenu, false);
        accountTrigger.addEventListener('click', () => {
            const open = accountTrigger.getAttribute('aria-expanded') !== 'true';
            closeTransientPanels(accountMenu);
            setDisclosure(accountTrigger, accountMenu, open);
        });
    }

    if (searchTrigger && searchPanel) {
        setDisclosure(searchTrigger, searchPanel, false);
        searchTrigger.addEventListener('click', () => {
            const open = searchTrigger.getAttribute('aria-expanded') !== 'true';
            closeTransientPanels(searchPanel);
            setDisclosure(searchTrigger, searchPanel, open, searchPanel.querySelector('input'));
        });
    }

    if (megaTrigger && megaMenu) {
        setMegaOpen(false);
        megaTrigger.addEventListener('click', () => {
            const open = megaTrigger.getAttribute('aria-expanded') !== 'true';
            closeTransientPanels(megaMenu);
            setMegaOpen(open);
        });

        megaGroup?.addEventListener('pointerenter', () => {
            closeTransientPanels(megaMenu);
            setMegaOpen(true);
        });
        megaGroup?.addEventListener('pointerleave', scheduleMegaClose);
        megaGroup?.addEventListener('focusin', () => {
            clearMegaCloseTimer();
            closeTransientPanels(megaMenu);
            setMegaOpen(true);
        });
        megaGroup?.addEventListener('focusout', (event) => {
            if (!megaGroup.contains(event.relatedTarget)) {
                scheduleMegaClose();
            }
        });
    }

    if (mobileTrigger && mobileDrawer && mobileBackdrop) {
        setDisclosure(mobileTrigger, mobileDrawer, false);
        setOpen(mobileBackdrop, false);

        mobileTrigger.addEventListener('click', () => {
            const open = mobileTrigger.getAttribute('aria-expanded') !== 'true';
            closeTransientPanels();
            setDisclosure(mobileTrigger, mobileDrawer, open, mobileDrawer.querySelector('input, a, button'));
            setOpen(mobileBackdrop, open);
            document.body.classList.toggle('drawer-open', open);
        });

        mobileClose?.addEventListener('click', () => closeDrawer(true));
        mobileBackdrop.addEventListener('click', () => closeDrawer(true));
    }

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (accountMenu && accountTrigger && !accountMenu.contains(target) && !accountTrigger.contains(target)) {
            setDisclosure(accountTrigger, accountMenu, false);
        }

        if (searchPanel && searchTrigger && !searchPanel.contains(target) && !searchTrigger.contains(target)) {
            setDisclosure(searchTrigger, searchPanel, false);
        }

        if (megaMenu && megaTrigger && !megaMenu.contains(target) && !megaTrigger.contains(target)) {
            setMegaOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const megaWasOpen = megaTrigger?.getAttribute('aria-expanded') === 'true';
        closeTransientPanels();
        closeDrawer(true);

        if (megaWasOpen) {
            megaTrigger?.focus();
        }
    });

    const carousel = document.querySelector('[data-hero-carousel]');
    const slides = carousel ? [...carousel.querySelectorAll('[data-hero-slide]')] : [];
    const dots = carousel ? [...carousel.querySelectorAll('[data-hero-dot]')] : [];
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    if (carousel && slides.length > 1) {
        let index = 0;
        let timer = null;

        const displaySlide = (nextIndex) => {
            index = (nextIndex + slides.length) % slides.length;

            slides.forEach((slide, slideIndex) => {
                const active = slideIndex === index;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', String(!active));

                const video = slide.querySelector('[data-hero-video]');
                if (video) {
                    if (active && !reducedMotion) {
                        video.play().catch(() => undefined);
                    } else {
                        video.pause();
                        video.currentTime = 0;
                    }
                }
            });

            dots.forEach((dot, dotIndex) => {
                const active = dotIndex === index;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-selected', String(active));
            });
        };

        const stopAutoplay = () => window.clearInterval(timer);
        const startAutoplay = () => {
            stopAutoplay();
            if (!reducedMotion) {
                timer = window.setInterval(() => displaySlide(index + 1), 6500);
            }
        };

        carousel.querySelector('[data-hero-previous]')?.addEventListener('click', () => {
            displaySlide(index - 1);
            startAutoplay();
        });

        carousel.querySelector('[data-hero-next]')?.addEventListener('click', () => {
            displaySlide(index + 1);
            startAutoplay();
        });

        dots.forEach((dot, dotIndex) => {
            dot.addEventListener('click', () => {
                displaySlide(dotIndex);
                startAutoplay();
            });
        });

        carousel.addEventListener('mouseenter', stopAutoplay);
        carousel.addEventListener('mouseleave', startAutoplay);
        carousel.addEventListener('focusin', stopAutoplay);
        carousel.addEventListener('focusout', (event) => {
            if (!carousel.contains(event.relatedTarget)) startAutoplay();
        });

        let touchStartX = null;
        carousel.addEventListener('touchstart', (event) => {
            touchStartX = event.changedTouches[0]?.clientX ?? null;
        }, { passive: true });
        carousel.addEventListener('touchend', (event) => {
            const endX = event.changedTouches[0]?.clientX;
            if (touchStartX === null || typeof endX !== 'number') return;
            const distance = endX - touchStartX;
            if (Math.abs(distance) > 48) {
                displaySlide(index + (distance < 0 ? 1 : -1));
                startAutoplay();
            }
            touchStartX = null;
        }, { passive: true });

        displaySlide(0);
        startAutoplay();
    }

    const highJewelryCarousel = document.querySelector('[data-high-jewelry-carousel]');
    const highJewelryTrack = highJewelryCarousel?.querySelector('[data-high-jewelry-track]');
    const highJewelrySlides = highJewelryCarousel ? [...highJewelryCarousel.querySelectorAll('[data-high-jewelry-slide]')] : [];

    if (highJewelryCarousel && highJewelryTrack && highJewelrySlides.length > 1) {
        let highJewelryIndex = 0;
        let highJewelryTimer = null;

        const displayHighJewelrySlide = (nextIndex) => {
            highJewelryIndex = (nextIndex + highJewelrySlides.length) % highJewelrySlides.length;
            highJewelryTrack.style.transform = `translateX(-${highJewelryIndex * 100}%)`;

            highJewelrySlides.forEach((slide, slideIndex) => {
                const active = slideIndex === highJewelryIndex;
                slide.setAttribute('aria-hidden', String(!active));
                slide.toggleAttribute('inert', !active);
            });
        };

        const stopHighJewelryAutoplay = () => {
            if (highJewelryTimer !== null) {
                window.clearInterval(highJewelryTimer);
                highJewelryTimer = null;
            }
        };
        const startHighJewelryAutoplay = () => {
            stopHighJewelryAutoplay();
            if (!reducedMotion) {
                highJewelryTimer = window.setInterval(() => displayHighJewelrySlide(highJewelryIndex + 1), 4700);
            }
        };

        highJewelryCarousel.querySelector('[data-high-jewelry-previous]')?.addEventListener('click', () => {
            displayHighJewelrySlide(highJewelryIndex - 1);
            startHighJewelryAutoplay();
        });
        highJewelryCarousel.querySelector('[data-high-jewelry-next]')?.addEventListener('click', () => {
            displayHighJewelrySlide(highJewelryIndex + 1);
            startHighJewelryAutoplay();
        });
        highJewelryCarousel.addEventListener('mouseenter', stopHighJewelryAutoplay);
        highJewelryCarousel.addEventListener('mouseleave', startHighJewelryAutoplay);
        highJewelryCarousel.addEventListener('focusin', stopHighJewelryAutoplay);
        highJewelryCarousel.addEventListener('focusout', (event) => {
            if (!highJewelryCarousel.contains(event.relatedTarget)) {
                startHighJewelryAutoplay();
            }
        });

        let highJewelryTouchStartX = null;
        highJewelryCarousel.addEventListener('touchstart', (event) => {
            highJewelryTouchStartX = event.changedTouches[0]?.clientX ?? null;
        }, { passive: true });
        highJewelryCarousel.addEventListener('touchend', (event) => {
            const endX = event.changedTouches[0]?.clientX;
            if (highJewelryTouchStartX === null || typeof endX !== 'number') {
                return;
            }

            const distance = endX - highJewelryTouchStartX;
            if (Math.abs(distance) > 48) {
                displayHighJewelrySlide(highJewelryIndex + (distance < 0 ? 1 : -1));
                startHighJewelryAutoplay();
            }
            highJewelryTouchStartX = null;
        }, { passive: true });

        displayHighJewelrySlide(0);
        startHighJewelryAutoplay();
    }

    const weddingCarousel = document.querySelector('[data-wedding-carousel]');
    const weddingTrack = weddingCarousel?.querySelector('[data-wedding-track]');
    const weddingPages = weddingCarousel ? [...weddingCarousel.querySelectorAll('[data-wedding-page]')] : [];

    if (weddingCarousel && weddingTrack && weddingPages.length > 1) {
        let weddingPageIndex = 0;

        const displayWeddingPage = (nextIndex) => {
            weddingPageIndex = (nextIndex + weddingPages.length) % weddingPages.length;
            weddingTrack.style.transform = `translateX(-${weddingPageIndex * 100}%)`;

            weddingPages.forEach((page, pageIndex) => {
                const active = pageIndex === weddingPageIndex;
                page.setAttribute('aria-hidden', String(!active));
                page.toggleAttribute('inert', !active);
            });
        };

        weddingCarousel.querySelector('[data-wedding-previous]')?.addEventListener('click', () => {
            displayWeddingPage(weddingPageIndex - 1);
        });
        weddingCarousel.querySelector('[data-wedding-next]')?.addEventListener('click', () => {
            displayWeddingPage(weddingPageIndex + 1);
        });

        displayWeddingPage(0);
    }

    const newsCarousel = document.querySelector('[data-news-carousel]');
    const newsTrack = newsCarousel?.querySelector('[data-news-track]');
    const newsCards = newsCarousel ? [...newsCarousel.querySelectorAll('[data-news-card]')] : [];
    const newsPrevious = newsCarousel?.querySelector('[data-news-previous]');
    const newsNext = newsCarousel?.querySelector('[data-news-next]');

    if (newsCarousel && newsTrack && newsCards.length > 0) {
        let newsPageIndex = 0;

        const newsCardsPerPage = () => {
            if (window.innerWidth <= 680) return 1;
            if (window.innerWidth <= 900) return 2;

            return 3;
        };

        const displayNewsPage = (nextIndex) => {
            const cardsPerPage = newsCardsPerPage();
            const pageCount = Math.ceil(newsCards.length / cardsPerPage);
            newsPageIndex = (nextIndex + pageCount) % pageCount;
            newsTrack.style.transform = `translateX(-${newsPageIndex * 100}%)`;

            newsCards.forEach((card, cardIndex) => {
                const visible = cardIndex >= newsPageIndex * cardsPerPage
                    && cardIndex < (newsPageIndex + 1) * cardsPerPage;
                card.setAttribute('aria-hidden', String(!visible));
                card.toggleAttribute('inert', !visible);
            });

            const showControls = pageCount > 1;
            if (newsPrevious) newsPrevious.hidden = !showControls;
            if (newsNext) newsNext.hidden = !showControls;
        };

        newsPrevious?.addEventListener('click', () => displayNewsPage(newsPageIndex - 1));
        newsNext?.addEventListener('click', () => displayNewsPage(newsPageIndex + 1));
        window.addEventListener('resize', () => displayNewsPage(newsPageIndex));

        displayNewsPage(0);
    }

    const testimonials = document.querySelector('[data-testimonials]');
    const testimonialSelectors = testimonials ? [...testimonials.querySelectorAll('[data-testimonial-selector]')] : [];
    const testimonialPanels = testimonials ? [...testimonials.querySelectorAll('[data-testimonial-panel]')] : [];

    if (testimonials && testimonialSelectors.length > 1 && testimonialSelectors.length === testimonialPanels.length) {
        let testimonialIndex = 0;
        let testimonialTimer = null;

        const displayTestimonial = (nextIndex) => {
            testimonialIndex = (nextIndex + testimonialPanels.length) % testimonialPanels.length;

            testimonialSelectors.forEach((selector, index) => {
                const active = index === testimonialIndex;
                selector.classList.toggle('is-active', active);
                selector.setAttribute('aria-pressed', String(active));
            });
            testimonialPanels.forEach((panel, index) => {
                const active = index === testimonialIndex;
                panel.classList.toggle('is-active', active);
                panel.setAttribute('aria-hidden', String(!active));
                panel.toggleAttribute('inert', !active);
            });
        };

        const stopTestimonialAutoplay = () => {
            if (testimonialTimer !== null) {
                window.clearInterval(testimonialTimer);
                testimonialTimer = null;
            }
        };
        const startTestimonialAutoplay = () => {
            stopTestimonialAutoplay();
            if (!reducedMotion) {
                testimonialTimer = window.setInterval(() => displayTestimonial(testimonialIndex + 1), 6000);
            }
        };

        testimonialSelectors.forEach((selector, index) => {
            selector.addEventListener('click', () => {
                displayTestimonial(index);
                startTestimonialAutoplay();
            });
        });
        testimonials.addEventListener('mouseenter', stopTestimonialAutoplay);
        testimonials.addEventListener('mouseleave', startTestimonialAutoplay);
        testimonials.addEventListener('focusin', stopTestimonialAutoplay);
        testimonials.addEventListener('focusout', (event) => {
            if (!testimonials.contains(event.relatedTarget)) {
                startTestimonialAutoplay();
            }
        });

        displayTestimonial(0);
        startTestimonialAutoplay();
    }

    const year = document.getElementById('currentYear');
    if (year) year.textContent = String(new Date().getFullYear());
});
