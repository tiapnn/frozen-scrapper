<?php

namespace App\Console\Commands;

use App\Models\Product;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Illuminate\Console\Command;

class ScrapeCarrefourProducts extends Command
{
    protected $signature = 'scraper:store {url}';
    protected $description = 'Scrape and store first 5 products from Carrefour category';

    public function handle()
    {
        $url = $this->argument('url');

        try {
            $options = new ChromeOptions();
            $options->addArguments([
                '--disable-gpu',
                '--window-size=1920,1080',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-web-security',
                '--allow-running-insecure-content',
                '--disable-blink-features=AutomationControlled'
            ]);

            $options->setExperimentalOption('excludeSwitches', ['enable-automation']);
            $options->setExperimentalOption('useAutomationExtension', false);

            $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
            $options->addArguments(['--user-agent=' . $userAgent]);

            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
            $capabilities->setCapability('acceptInsecureCerts', true);

            $driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);

            // Navigate to target URL
            $driver->get($url);

            // Handle cookie modal
            try {
                $driver->wait(5)->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::id('onetrust-accept-btn-handler')
                    )
                );
                $driver->findElement(WebDriverBy::id('onetrust-accept-btn-handler'))->click();
            } catch (\Exception $e) {
                $this->info('No cookie modal found or already accepted');
            }

            $products = [];
            $scrollAttempts = 0;
            $maxScrolls = 10; // Prevent infinite scrolling
            
            while (count($products) < 5 && $scrollAttempts < $maxScrolls) {
                // Scroll down by a portion of the viewport
                $driver->executeScript(sprintf('
                    window.scrollTo({
                        top: %d,
                        behavior: "smooth"
                    });
                ', ($scrollAttempts + 1) * 800));
                
                sleep(2);
                
                // Scrape visible products
                $productElements = $driver->findElements(WebDriverBy::cssSelector('.product-card'));
                
                foreach ($productElements as $element) {
                    try {
                        // Clean and validate title
                        $title = $element->findElement(WebDriverBy::cssSelector('.product-card__title-link'))->getText();
                        $title = trim($title);
                        
                        // Skip if we already have this product
                        if (collect($products)->where('title', $title)->count() > 0) {
                            continue;
                        }
                        
                        // Clean and validate price
                        $priceText = $element->findElement(WebDriverBy::cssSelector('.product-card__price'))->getText();
                        $price = (float) preg_replace('/[^0-9,.]/', '', str_replace(',', '.', $priceText));
                        
                        // Validate image URL
                        $imageUrl = $element->findElement(WebDriverBy::cssSelector('.product-card__image'))->getAttribute('src');
                        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                            throw new \Exception('Invalid image URL');
                        }
                        
                        // Validate product URL
                        $productUrl = $element->findElement(WebDriverBy::cssSelector('.product-card__media-link'))->getAttribute('href');
                        if (!$productUrl) {
                            throw new \Exception('Missing product URL');
                        }
                
                        // Only add product if all fields are valid
                        if ($title && $price > 0) {
                            $products[] = [
                                'title' => $title,
                                'price' => $price,
                                'image_url' => $imageUrl,
                                'product_url' => $productUrl,
                            ];
                            $this->info("Scraped product: $title");
                            
                        }
                
                    } catch (\Exception $e) {
                        $this->warn("Failed to scrape product: " . $e->getMessage());
                        continue;
                    }
                }
                
                $scrollAttempts++;
            }

            // Take only first 5 products
            $products = array_slice($products, 0, 5);
            
            // Validate we have products before saving
            if (empty($products)) {
                throw new \Exception('No valid products found to save');
            }

            foreach ($products as $productData) {
                Product::create($productData);
            }

            $driver->quit();
            $this->info('Successfully stored 5 products');
            
        } catch (\Exception $e) {
            $this->error('Error scraping products: ' . $e->getMessage());
        }
    }
}
