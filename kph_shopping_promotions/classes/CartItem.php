<?php
// ============================================================
// KameraPH | Shopping Cart Module | CartItem
// ============================================================
// Represents a single line item in a shopping cart.
// All product data (name, price, image) is an UNVERIFIED snapshot
// supplied by the client at add-time. A future CatalogProvider
// will provide authoritative data.
// ============================================================

class CartItem
{
    public int $CartItemID;
    public int $CartID;
    public int $ProductID;
    public string $ProductName;
    public float $UnitPrice;
    public ?string $ImageURL;
    public int $Quantity;

    public function __construct(
        int $CartItemID,
        int $CartID,
        int $ProductID,
        string $ProductName,
        float $UnitPrice,
        ?string $ImageURL,
        int $Quantity
    ) {
        $this->CartItemID = $CartItemID;
        $this->CartID = $CartID;
        $this->ProductID = $ProductID;
        $this->ProductName = $ProductName;
        $this->UnitPrice = $UnitPrice;
        $this->ImageURL = $ImageURL;
        $this->Quantity = $Quantity;
    }

    /**
     * Create a CartItem from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['CartItemID'],
            (int) $row['CartID'],
            (int) $row['ProductID'],
            $row['ProductName'],
            (float) $row['UnitPrice'],
            $row['ImageURL'] ?? null,
            (int) $row['Quantity']
        );
    }

    /**
     * Get the line subtotal (unit price * quantity).
     */
    public function getSubtotal(): float
    {
        return round($this->UnitPrice * $this->Quantity, 2);
    }

    /**
     * Convert to an array for API responses.
     */
    public function toArray(): array
    {
        return [
            'CartItemID' => $this->CartItemID,
            'CartID' => $this->CartID,
            'ProductID' => $this->ProductID,
            'ProductName' => $this->ProductName,
            'UnitPrice' => $this->UnitPrice,
            'ImageURL' => $this->ImageURL,
            'Quantity' => $this->Quantity,
            'Subtotal' => $this->getSubtotal(),
        ];
    }
}