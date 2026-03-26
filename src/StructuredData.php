<?php

namespace AgentReady;

/**
 * Generates Schema.org JSON-LD structured data.
 * Provides rich semantic markup that AI agents can directly consume.
 */
class StructuredData
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Generate a full JSON-LD block ready to inject into HTML.
     */
    public function toHtml(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // Prevent XSS in script blocks by escaping sequences that could close the tag
        $json = str_replace('</', '<\/', $json);
        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }

    /**
     * Build WebPage structured data from extracted page content.
     */
    public function buildWebPage(array $extracted, array $extra = []): array
    {
        $siteName = $this->config->get('site.name', '');
        $siteUrl = rtrim($this->config->get('site.url', ''), '/');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $extracted['title'] ?? '',
            'description' => $extracted['description'] ?? '',
            'url' => $extra['url'] ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '')),
            'inLanguage' => $this->config->get('site.language', 'en'),
        ];

        if ($siteName) {
            $data['isPartOf'] = [
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        }

        if (!empty($extracted['images'])) {
            $data['primaryImageOfPage'] = [
                '@type' => 'ImageObject',
                'url' => $extracted['images'][0]['src'] ?? '',
                'caption' => $extracted['images'][0]['alt'] ?? '',
            ];
        }

        if (!empty($extra['datePublished'])) {
            $data['datePublished'] = $extra['datePublished'];
        }
        if (!empty($extra['dateModified'])) {
            $data['dateModified'] = $extra['dateModified'];
        }

        return array_merge($data, $this->filterExtraFields($extra));
    }

    /**
     * Build RealEstateListing structured data.
     * Tailored for property/real estate sites.
     */
    public function buildRealEstateListing(array $properties): array
    {
        $siteUrl = rtrim($this->config->get('site.url', ''), '/');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'RealEstateListing',
        ];

        $fieldMap = [
            'name' => 'name',
            'title' => 'name',
            'description' => 'description',
            'url' => 'url',
            'datePosted' => 'datePosted',
            'image' => 'image',
            'images' => 'image',
        ];

        foreach ($fieldMap as $input => $schema) {
            if (!empty($properties[$input])) {
                $data[$schema] = $properties[$input];
            }
        }

        // Build Offer (price)
        if (!empty($properties['price'])) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => $properties['price'],
                'priceCurrency' => $properties['currency'] ?? 'EUR',
            ];

            if (!empty($properties['availability'])) {
                $data['offers']['availability'] = 'https://schema.org/' . $properties['availability'];
            }
        }

        // Build the property itself
        $property = ['@type' => 'SingleFamilyResidence'];

        $propertyFields = [
            'address' => null,
            'numberOfRooms' => 'numberOfRooms',
            'numberOfBedrooms' => 'numberOfBedrooms',
            'numberOfBathrooms' => 'numberOfBathroomsTotal',
            'floorSize' => null,
            'lotSize' => null,
            'yearBuilt' => 'yearBuilt',
            'propertyType' => null,
        ];

        foreach ($propertyFields as $field => $schemaField) {
            if (empty($properties[$field])) continue;

            switch ($field) {
                case 'address':
                    if (is_array($properties['address'])) {
                        $property['address'] = array_merge(
                            ['@type' => 'PostalAddress'],
                            $properties['address']
                        );
                    } else {
                        $property['address'] = [
                            '@type' => 'PostalAddress',
                            'streetAddress' => $properties['address'],
                        ];
                    }
                    break;

                case 'floorSize':
                    $property['floorSize'] = [
                        '@type' => 'QuantitativeValue',
                        'value' => $properties['floorSize'],
                        'unitCode' => $properties['floorSizeUnit'] ?? 'MTK', // m²
                    ];
                    break;

                case 'lotSize':
                    $property['lotSize'] = [
                        '@type' => 'QuantitativeValue',
                        'value' => $properties['lotSize'],
                        'unitCode' => $properties['lotSizeUnit'] ?? 'MTK',
                    ];
                    break;

                case 'propertyType':
                    $typeMap = [
                        'house' => 'SingleFamilyResidence',
                        'apartment' => 'Apartment',
                        'condo' => 'Accommodation',
                        'townhouse' => 'House',
                        'studio' => 'Apartment',
                        'villa' => 'House',
                    ];
                    $property['@type'] = $typeMap[strtolower($properties['propertyType'])] ?? 'Accommodation';
                    break;

                default:
                    if ($schemaField) {
                        $property[$schemaField] = $properties[$field];
                    }
                    break;
            }
        }

        // Add amenities/features
        if (!empty($properties['features'])) {
            $property['amenityFeature'] = array_map(function ($feature) {
                return [
                    '@type' => 'LocationFeatureSpecification',
                    'name' => $feature,
                    'value' => true,
                ];
            }, (array)$properties['features']);
        }

        // Add coordinates
        if (!empty($properties['latitude']) && !empty($properties['longitude'])) {
            $property['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $properties['latitude'],
                'longitude' => $properties['longitude'],
            ];
        }

        $data['about'] = $property;

        return $data;
    }

    /**
     * Build Organization structured data from config.
     */
    public function buildOrganization(): array
    {
        $org = $this->config->get('structured_data.organization', []);
        $site = $this->config->get('site', []);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $org['name'] ?? $site['name'] ?? '',
            'url' => $org['url'] ?? $site['url'] ?? '',
        ];

        if (!empty($org['logo'])) {
            $data['logo'] = $org['logo'];
        }

        if (!empty($site['contact_email'])) {
            $data['email'] = $site['contact_email'];
        }

        if (!empty($site['contact_phone'])) {
            $data['telephone'] = $site['contact_phone'];
        }

        return $data;
    }

    /**
     * Build BreadcrumbList structured data.
     */
    public function buildBreadcrumbs(array $items): array
    {
        $siteUrl = rtrim($this->config->get('site.url', ''), '/');

        $listItems = [];
        foreach ($items as $i => $item) {
            $listItem = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
            ];

            if (!empty($item['url'])) {
                $url = $item['url'];
                if (strpos($url, 'http') !== 0) {
                    $url = $siteUrl . '/' . ltrim($url, '/');
                }
                $listItem['item'] = $url;
            }

            $listItems[] = $listItem;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
    }

    /**
     * Build FAQPage structured data.
     */
    public function buildFAQ(array $faqs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(function ($faq) {
                return [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ],
                ];
            }, $faqs),
        ];
    }

    /**
     * Merge multiple structured data objects into a single @graph.
     */
    public function merge(array ...$items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_map(function ($item) {
                unset($item['@context']);
                return $item;
            }, $items),
        ];
    }

    private function filterExtraFields(array $extra): array
    {
        $reserved = ['url', 'datePublished', 'dateModified'];
        return array_diff_key($extra, array_flip($reserved));
    }
}
