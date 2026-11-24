<?php
// ============================================================
// KameraPH | Shopping Cart Module | CatalogProvider
// ============================================================
// Integration seam for a future Product Catalog module.
//
// When a real catalog DB/API exists, implement getProduct() to return
// authoritative product data (price, stock, name, image) and
// validateStock() to enforce real stock limits.
//
// Until then, both methods are stubs:
// - getProduct() returns null (no catalog data available)
// - validateStock() returns true (no stock limit to enforce)
//
// The Cart class treats all client-supplied snapshots as UNVERIFIED.
// Responses include "priceSource":"unverified_snapshot","verified":false.
// ============================================================

class CatalogProvider
{
    /**
     * Fetch authoritative product data from a future catalog.
     *
     * @param int $productId
     * @return array|null {ProductID, ProductName, UnitPrice, ImageURL, Stock}
     *                     or null when catalog is unavailable.
     */
    public static function getProduct(int $productId): ?array
    {
        // STUB: No Product Catalog module exists yet.
        // When available, replace with a real lookup, e.g.:
        //   return (new ProductCatalog())->find($productId);
        return null;
    }

    /**
     * Check whether a product has enough stock for a given quantity.
     *
     * @param int $productId
     * @param int $quantity
     * @return bool True if stock is sufficient (or unlimited / unknown).
     */
    public static function validateStock(int $productId, int $quantity): bool
    {
        // STUB: No stock data available yet. Default to true.
        // When available, replace with real check, e.g.:
        //   $product = self::getProduct($productId);
        //   return $product !== null && $product['Stock'] >= $quantity;
        return true;
    }
}