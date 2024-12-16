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
    protected $signature = 'scraper:store {url} {--json : Output as JSON instead of storing}';
    protected $description = 'Scrape first 5 products from Carrefour category and store or output as JSON';

    private const MAX_PRODUCTS = 5;
    private const MAX_SCROLL_ATTEMPTS = 10;
    private const SCROLL_DELAY = 2;
    private const SCROLL_DISTANCE = 800;

    private RemoteWebDriver $driver;

    public function handle()
    {
        try {
            $products = $this->scrapeProducts($this->argument('url'));
            
            return $this->option('json') 
                ? $this->outputJson($products)
                : $this->storeProducts($products);
                
        } catch (\Exception $e) {
            $this->error('Error scraping products: ' . $e->getMessage());
            return 1;
        }
    }

    private function scrapeProducts(string $url): array 
    {
        $this->initializeDriver();
        $this->navigateToUrl($url);
        $this->handleCookieConsent();

        $products = $this->scrollAndCollectProducts();
        
        $this->driver->quit();
        
        return array_slice($products, 0, self::MAX_PRODUCTS);
    }

    private function initializeDriver(): void
    {
        $options = $this->getChromiumOptions();
        $capabilities = $this->getCapabilities($options);
        
        $this->driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);
    }

    private function getChromiumOptions(): ChromeOptions
    {
        $options = new ChromeOptions();
        
        $options->addArguments([
            '--disable-gpu',
            '--window-size=1920,1080',
            '--no-sandbox',
            '--disable-dev-shm-usage', 
            '--disable-web-security',
            '--allow-running-insecure-content',
            '--disable-blink-features=AutomationControlled',
            '--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ]);

        $options->setExperimentalOption('excludeSwitches', ['enable-automation']);
        $options->setExperimentalOption('useAutomationExtension', false);

        return $options;
    }

    private function getCapabilities(ChromeOptions $options): DesiredCapabilities
    {
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        $capabilities->setCapability('acceptInsecureCerts', true);
        
        return $capabilities;
    }

    private function navigateToUrl(string $url): void
    {
        $this->driver->get($url);
    }

    private function handleCookieConsent(): void
    {
        try {
            $this->driver->wait(5)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::id('onetrust-accept-btn-handler')
                )
            );
            $this->driver->findElement(WebDriverBy::id('onetrust-accept-btn-handler'))->click();
        } catch (\Exception $e) {
            $this->info('No cookie modal found or already accepted');
        }
    }

    private function scrollAndCollectProducts(): array
    {
        $products = [];
        $scrollAttempts = 0;

        while (count($products) < self::MAX_PRODUCTS && $scrollAttempts < self::MAX_SCROLL_ATTEMPTS) {
            $this->scroll($scrollAttempts);
            sleep(self::SCROLL_DELAY);

            $newProducts = $this->scrapeVisibleProducts($products);
            $products = array_merge($products, $newProducts);
            
            $scrollAttempts++;
        }

        if (empty($products)) {
            throw new \Exception('No valid products found to save');
        }

        return $products;
    }

    private function scroll(int $attempt): void
    {
        $this->driver->executeScript(sprintf(
            'window.scrollTo({ top: %d, behavior: "smooth" });',
            ($attempt + 1) * self::SCROLL_DISTANCE
        ));
    }

    private function scrapeVisibleProducts(array $existingProducts): array
    {
        $products = [];
        $elements = $this->driver->findElements(WebDriverBy::cssSelector('.product-card'));

        foreach ($elements as $element) {
            try {
                $product = $this->extractProductData($element);
                
                if ($this->isValidProduct($product) && !$this->isDuplicate($product, $existingProducts)) {
                    $products[] = $product;
                    $this->info("Scraped product: {$product['title']}");
                }
            } catch (\Exception $e) {
                $this->warn("Failed to scrape product: " . $e->getMessage());
            }
        }

        return $products;
    }

    private function extractProductData($element): array
    {
        $title = trim($element->findElement(WebDriverBy::cssSelector('.product-card__title-link'))->getText());
        
        $priceText = $element->findElement(WebDriverBy::cssSelector('.product-card__price'))->getText();
        $price = (float) preg_replace('/[^0-9,.]/', '', str_replace(',', '.', $priceText));

        $imageUrl = $element->findElement(WebDriverBy::cssSelector('.product-card__image'))->getAttribute('src');
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid image URL');
        }

        $productUrl = $element->findElement(WebDriverBy::cssSelector('.product-card__media-link'))->getAttribute('href');
        if (!$productUrl) {
            throw new \Exception('Missing product URL');
        }

        return [
            'title' => $title,
            'price' => $price,
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
        ];
    }

    private function isValidProduct(array $product): bool
    {
        return !empty($product['title']) && $product['price'] > 0;
    }

    private function isDuplicate(array $product, array $existingProducts): bool
    {
        return collect($existingProducts)->where('title', $product['title'])->count() > 0;
    }

    private function outputJson(array $products): int
    {
        $this->line(json_encode($products, JSON_PRETTY_PRINT));
        return 0;
    }

    private function storeProducts(array $products): int
    {
        foreach ($products as $productData) {
            Product::create($productData);
        }
        
        $this->info('Successfully stored ' . count($products) . ' products');
        return 0;
    }
}