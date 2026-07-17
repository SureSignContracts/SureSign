<?php

namespace App\Services;

/**
 * A small, curated index into the public SureSign User Guide
 * (docs.suresigncontracts.app, built from mkdocs.yml + docs/ at the repo
 * root — see project-context.md's "Documentation System" section).
 *
 * This is a hand-picked subset of the ~114-page guide, not a mirror of it —
 * docs/ remains the single source of truth. Every slug below corresponds to
 * a real page listed in mkdocs.yml's `nav`; titles and summaries are drawn
 * from that page's actual content. Do not add an entry here without a
 * matching page in docs/ — this index is deliberately static (no runtime
 * scraping of the live docs site, per the standing "don't invent routes,
 * don't scrape at runtime" requirement).
 *
 * internal-docs/ (platform-admin-only content) is never referenced here —
 * only nothing in docs/, the public tree, ever appears in this index.
 */
class KnowledgeBaseService
{
    public const DOCS_BASE_URL = 'https://docs.suresigncontracts.app';

    public const CATEGORIES = [
        'getting_started'      => 'Getting Started',
        'contracts'             => 'Contracts',
        'ai_analysis'           => 'Automated Contract Analysis',
        'trade_packages'        => 'Trade Packages',
        'commercial'            => 'Commercial',
        'payment_applications'  => 'Payment Applications',
        'notices'               => 'Notices',
        'variations'            => 'Variations',
        'programme'             => 'Programme',
        'delay_eot'             => 'Delay and EOT',
        'documents'             => 'Documents',
        'notifications'         => 'Notifications',
        'account_access'        => 'Account and Access',
        'troubleshooting'       => 'Troubleshooting',
    ];

    /** category => [ [title, summary, slug], ... ] — slug maps to docs/{slug}.md. */
    private const ARTICLES = [
        'getting_started' => [
            ['Getting Started', "This section covers everything you need before you start using SureSign day to day: signing in, understanding the dashboard, and finding your way around.", 'getting-started/overview'],
            ['First-Day Checklist', 'Use this checklist the first time you sign in to SureSign.', 'getting-started/first-day-checklist'],
        ],
        'contracts' => [
            ['Contracts', "The Contracts section of a project holds the formal contract(s) for that project: the main contract, subcontracts, consultant appointments, or supplier agreements.", 'contracts/overview'],
            ['Uploading a Contract', 'Attaching the actual contract document (PDF, Word, or text file) to a contract record, so it can be stored, downloaded later, and optionally analysed by AI.', 'contracts/uploading-a-contract'],
        ],
        'ai_analysis' => [
            ['AI in SureSign', 'SureSign uses AI analysis in two places, both opt-in and controlled by your organisation administrator.', 'ai/overview'],
            ['AI Analysis (Contracts)', 'AI analysis reads an uploaded contract document and extracts a structured summary: key terms, payment rules, parties, key dates, and programme milestones.', 'contracts/ai-analysis'],
            ['Failed Analysis', 'If an analysis cannot be completed, its status becomes Failed, and the progress indicator or analysis history list shows what happened.', 'ai/failed-analysis'],
        ],
        'trade_packages' => [
            ['Trade Packages', 'A trade package represents a distinct piece of work let to a subcontractor within a project — for example Groundworks, Brickwork, or M&E.', 'trade-packages/overview'],
            ['Creating a Trade Package', 'Choose standard trade packages from a catalogue, or add a custom one, to generate a trade package workspace for a project.', 'trade-packages/creating-a-trade-package'],
        ],
        'commercial' => [
            ['Commercial', 'Commercial covers the money side of a project: payment applications, payment notices, pay less notices, retention, and final accounts.', 'commercial/overview'],
            ['Final Account', "The Final Account brings together the total financial position of a contract or trade package at the end of the works.", 'commercial/final-account'],
        ],
        'payment_applications' => [
            ['Payment Applications', "A payment application is a claim for payment for work carried out in a given valuation period, raised against a contract or trade package.", 'commercial/payment-applications'],
            ['Application Valuations', 'The detailed breakdown behind a payment application\'s headline figures — measured works, variations, and materials on site.', 'commercial/application-valuations'],
        ],
        'notices' => [
            ['Payment Notices', 'A payment notice states the sum the paying party considers due in respect of a payment application.', 'commercial/payment-notices'],
            ['Pay Less Notices', 'A pay less notice states that the paying party intends to pay less than the notified sum, and why.', 'commercial/pay-less-notices'],
        ],
        'variations' => [
            ['Variations', 'A variation is a change to the contracted works: an addition, omission, substitution, or other instructed change, tracked from instruction through to agreement.', 'variations/overview'],
            ['Creating a Variation', 'How to raise a new variation against a contract or trade package.', 'variations/creating-a-variation'],
        ],
        'programme' => [
            ['Programme', "The Programme section tracks a project's key dates and milestones — planned, forecast, and actual — for each contract.", 'programme/overview'],
            ['Programme Milestones', 'Adding milestones manually, or seeding them from a confirmed AI analysis, and tracking them in Table or Timeline view.', 'programme/milestones'],
        ],
        'delay_eot' => [
            ['Delay and Extension of Time', 'Covers three related record types: Delay Events, Extension of Time (EOT) Requests, and Loss & Expense claims.', 'delay-and-eot/overview'],
            ['Extension of Time (EOT) Requests', 'A formal request for additional time to complete the works, usually linked to one or more delay events.', 'delay-and-eot/extension-of-time'],
        ],
        'documents' => [
            ['Documents', 'SureSign keeps uploaded documents and generated documents (PDFs and Excel workbooks) together in one place for a project.', 'documents/overview'],
            ['File Restrictions', 'SureSign accepts common document and image formats for general uploads, including PDF, Word, Excel, plain text, and standard images.', 'documents/file-restrictions'],
        ],
        'notifications' => [
            ['Notifications and Activity', 'SureSign creates in-app notifications for events such as file uploads, document generation, AI analysis completing, and status changes.', 'notifications/overview'],
            ['Notification Bell', 'The bell icon shows an unread-count badge and a dropdown grouped by Critical, Today, Earlier, and recently Read notifications.', 'notifications/notification-bell'],
        ],
        'account_access' => [
            ['Roles in SureSign', 'Every SureSign account has a role that determines what you can see and do.', 'roles/overview'],
            ['Settings and Branding', "Your organisation's settings cover branding, company information, and your own password.", 'settings/overview'],
        ],
        'troubleshooting' => [
            ['Troubleshooting', 'Common problems and what to do about them.', 'troubleshooting/index'],
            ['Troubleshooting: Sign-In', 'What to check if your email or password is not recognised when signing in.', 'troubleshooting/sign-in'],
            ['Troubleshooting: Permissions and Access', 'What it means when you cannot open a project or record — usually an access or existence issue, not an error.', 'troubleshooting/permissions'],
        ],
    ];

    /**
     * Full curated index, grouped by category, with each article's full
     * public URL resolved. Optionally filtered by a search term matched
     * against title/summary/category label (case-insensitive substring).
     */
    public static function search(?string $query = null): array
    {
        $query = $query !== null ? trim(mb_strtolower($query)) : '';

        $results = [];

        foreach (self::ARTICLES as $categoryKey => $articles) {
            $categoryLabel = self::CATEGORIES[$categoryKey];
            $matched = [];

            foreach ($articles as [$title, $summary, $slug]) {
                if ($query !== '' && !self::matches($query, $title, $summary, $categoryLabel)) {
                    continue;
                }

                $matched[] = [
                    'title'   => $title,
                    'summary' => $summary,
                    'url'     => self::DOCS_BASE_URL.'/'.$slug.'/',
                ];
            }

            if (!empty($matched)) {
                $results[] = [
                    'category' => $categoryKey,
                    'label'    => $categoryLabel,
                    'articles' => $matched,
                ];
            }
        }

        return $results;
    }

    private static function matches(string $query, string $title, string $summary, string $categoryLabel): bool
    {
        return str_contains(mb_strtolower($title), $query)
            || str_contains(mb_strtolower($summary), $query)
            || str_contains(mb_strtolower($categoryLabel), $query);
    }

    /**
     * Context-aware suggestions — a single centralized resolver from an
     * app route (e.g. "/app/projects/12/commercial") to the most relevant
     * KB categories, used to surface "on this page" doc links without
     * scattering route-to-category logic across controllers/components.
     */
    public static function categoriesForRoute(string $route): array
    {
        $map = [
            '#^/app/projects/[^/]+/commercial#'        => ['commercial', 'payment_applications', 'notices'],
            '#^/app/projects/[^/]+/contracts#'          => ['contracts', 'ai_analysis'],
            '#^/app/projects/[^/]+/subcontracts#'       => ['trade_packages'],
            '#^/app/projects/[^/]+/variations#'         => ['variations'],
            '#^/app/projects/[^/]+/programme#'          => ['programme'],
            '#^/app/projects/[^/]+/delay-eot#'          => ['delay_eot'],
            '#^/app/projects/[^/]+/documents#'          => ['documents'],
            '#^/app/documents#'                         => ['documents'],
            '#^/app/ai#'                                => ['ai_analysis'],
            '#^/app/notifications#'                     => ['notifications'],
            '#^/app/settings#'                           => ['account_access'],
            '#^/app$#'                                  => ['getting_started'],
        ];

        foreach ($map as $pattern => $categories) {
            if (preg_match($pattern, $route)) {
                return $categories;
            }
        }

        return [];
    }
}
