<?php
namespace Rissc\Printformer\Helper;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Checkout\Model\Cart as CartModel;
use Magento\Wishlist\Model\Item as WishlistItemModel;
use Rissc\Printformer\Helper\Session as SessionHelper;
use Rissc\Printformer\Helper\Config as ConfigHelper;
use Rissc\Printformer\Helper\Product as ProductHelper;
use Rissc\Printformer\Model\Product as PrintformerProduct;

class Cart extends AbstractHelper
{
    private Product $productHelper;
    private CartModel $cartModel;
    private WishlistItemModel $wishlistItemModel;
    private Config $configHelper;

    /**
     * @param Context $context
     * @param Product $productHelper
     * @param CartModel $cartModel
     * @param WishlistItemModel $wishlistItemModel
     */
    public function __construct(
        Context $context,
        ProductHelper $productHelper,
        CartModel $cartModel,
        WishlistItemModel $wishlistItemModel,
        ConfigHelper $configHelper
    ) {
        parent::__construct($context);
        $this->productHelper = $productHelper;
        $this->cartModel = $cartModel;
        $this->wishlistItemModel = $wishlistItemModel;
        $this->configHelper = $configHelper;
    }

    /**
     * Load draftId from buy-Request of quote-Item
     * @param $quoteItem
     * @param int $productId
     * @param int $printformerProductId
     * @return false|string
     */
    public function loadDraftFromQuoteItem(
        $quoteItem,
        int $productId,
        int $printformerProductId
    )
    {
        $resultDraft = false;
        if (($productId && $productId == $quoteItem->getProduct()->getId()) || (empty($productId))) {
            $quoteItemDraftsField = $quoteItem->getData(SessionHelper::SESSION_KEY_PRINTFORMER_DRAFTID);
            if (!empty($quoteItemDraftsField)) {
                $quoteItemDraftsCollectionItems = $this->productHelper->loadDraftItemsByIds($quoteItemDraftsField);
                foreach ($quoteItemDraftsCollectionItems as $quoteItemCollectionItem) {
                    if ($quoteItemCollectionItem->getProductId() == $productId && $quoteItemCollectionItem->getPrintformerProductId() == $printformerProductId) {
                        $resultDraft = $quoteItemCollectionItem->getDraftId();
                    }
                }
            }
        }

        return $resultDraft;
    }

    /**
     * @param $draftHashRelations
     * @param $productId
     * @param $printformerProductId
     * @param $draftId
     * @return array
     */
    public function updateDraftHashRelations(
        $draftHashRelations,
        $productId,
        $printformerProductId,
        $draftId
    )
    {
        if (is_array($draftHashRelations)) {
            if(isset($draftHashRelations[$productId])) {
                $draftHashRelationsProduct = $draftHashRelations[$productId];
            }
            if (!isset($draftHashRelationsProduct)) {
                $draftHashRelations[$productId] = [];
            }
            if (is_array($draftHashRelations[$productId])){
                $draftHashRelations[$productId][$printformerProductId] = $draftId;
            }
        }
        return $draftHashRelations;
    }

    /**
     * Get Draft id depends by your request / position from where request is sent.
     *
     * @return string
     */
    public function searchAndLoadDraftId(PrintformerProduct $printformerProduct)
    {
        $draftId = null;
        $productId = $this->_request->getParam('product_id');
        // Get draft ID on cart product edit page
        if ($this->_request->getActionName() == 'configure' && $this->_request->getParam('id') && $this->_request->getParam('product_id')) {
            $quoteItem = null;
            $wishlistItem = null;
            $id = (int)$this->_request->getParam('id');
            $productId = (int)$this->_request->getParam('product_id');
            if ($id) {
                switch ($this->_request->getModuleName()) {
                    case 'checkout':
                        $quoteItem = $this->cartModel->getQuote()->getItemById($id);
                        if ($quoteItem && $productId == $quoteItem->getProduct()->getId()) {
                            if ($this->configHelper->useChildProduct($quoteItem->getProductType())) {
                                $children = $quoteItem->getChildren();
                                if (!empty($children)) {
                                    $firstChild = $children[0];
                                    if (!empty($firstChild)) {
                                        $draftId = $this->loadDraftFromQuoteItem($firstChild, $printformerProduct->getProductId(), $printformerProduct->getId());
                                    }
                                }
                            } else {
                                $draftId = $this->loadDraftFromQuoteItem($quoteItem, $printformerProduct->getProductId(), $printformerProduct->getId());
                            }
                        }
                        break;
                    case 'wishlist':
                        $wishlistItem = $this->wishlistItemModel->loadWithOptions($id);
                        $buyRequest = $wishlistItem->getBuyRequest();
                        $draftField = $buyRequest->getData($this->productHelper::COLUMN_NAME_DRAFTID);
                        if (!empty($draftField)) {
                            $draftHashArray = explode(',', $draftField);
                            foreach($draftHashArray as $draftHash) {
                                $draftItem = $this->productHelper->getDraftById($draftHash);
                                if ($draftItem) {
                                    $pfProductId = $draftItem->getData('printformer_product_id');
                                    $productId = $draftItem->getData('product_id');
                                    if(!empty($productId) && !empty($pfProductId)) {
                                        if ($pfProductId == $printformerProduct->getId() && $productId == $printformerProduct->getProductId()) {
                                            $draftField = $wishlistItem->getOptionByCode($this->productHelper::COLUMN_NAME_DRAFTID)->getValue();
                                            if (!empty($draftField)) {
                                                $draftHashArray = explode(',', $draftField);
                                                foreach($draftHashArray as $draftHash) {
                                                    $draft = $this->productHelper->getDraftById($draftHash);
                                                    if ($draft->getPrintformerProductId() == $pfProductId && $draft->getProductId() == $productId) {
                                                        $draftId = $draftHash;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        //todo?: adjust to get draft id for child-products like on function loadDraftFromQuoteItem

                        break;
                    default:
                        break;
                }
            }
        } else {
            $productId = $printformerProduct->getProductId();
            $pfProductId = $printformerProduct->getId();
            $draftId = $this->productHelper->getDraftId($pfProductId, $productId);
            if (empty($draftId) || $this->draftIsAlreadyUsed($draftId)) {
                $draftId = null;
            }
        }

        return $draftId;
    }

    /**
     * Verify if draft is used anywhere (currently checked on quote- and wishlist-items)
     *
     * @param $draftId
     * @return bool
     */
    public function draftIsAlreadyUsed($draftId)
    {
        $resultIsInUsage = false;
        try {
            $inCartUsage = $this->draftIsAlreadyUsedInCart($draftId);
            $inWishlistUsage = $this->draftIsAlreadyUsedInWishlist($draftId);
            if ($inCartUsage || $inWishlistUsage) {
                $resultIsInUsage = true;
            } else {
                $resultIsInUsage = false;
            }
        } catch (\Exception $e) {
        }
        return $resultIsInUsage;
    }

    private function draftIsAlreadyUsedInWishlist($draftId)
    {
        $items = $this->wishlistItemModel->getCollection()->getItems();
        $result = false;
        try {
            foreach ($items as $item) {
                $buyRequest = $item->getBuyRequest();
                $draftField = $buyRequest->getData($this->productHelper::COLUMN_NAME_DRAFTID);
                if (!empty($draftField) && !empty($draftId)) {
                    if (str_contains($draftField, $draftId)) {
                        $result = true;
                    }
                }
            }
        } catch(\Exception $e){
        }
        return $result;
    }

    /**
     * To verify if current draft is used in any quote item
     *
     * @param $draftId
     * @return bool
     */
    public function draftIsAlreadyUsedInCart(
        $draftId
    )
    {
        $quoteItems = $this->cartModel->getQuote()->getItems();
        $result = false;

        if (is_array($quoteItems)) {
            foreach ($quoteItems as $quoteItem) {
                if ($quoteItem->getProductType() == ConfigurableType::TYPE_CODE) {
                    if ($quoteItem) {
                        if ($quoteItem->getDraftId() == $draftId) {
                            $result = true;
                        }

                        if (!empty($children = $quoteItem->getChildren())) {
                            foreach ($children as $child) {
                                if ($child->getData($this->productHelper::COLUMN_NAME_DRAFTID) == $draftId) {
                                    $result = true;
                                }
                            }
                        }
                    }
                }
                else {
                    if ($quoteItem->getData($this->productHelper::COLUMN_NAME_DRAFTID) == $draftId) {
                        $result = true;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Verify if draft-is is used in any wishlist-item
     *
     * @param $draftId
     * @return bool
     */
    public function draftIsAlreadyUsedInCurrentWishlist($draftId)
    {
        $result = false;
        try {
            $id = (int)$this->_request->getParam('id');
            $wishlistItem = $this->wishlistItemModel->loadWithOptions($id);
            $buyRequest = $wishlistItem->getBuyRequest();
            $draftField = $buyRequest->getData($this->productHelper::COLUMN_NAME_DRAFTID);

            if (!empty($draftField) && !empty($draftId)) {
                if (str_contains($draftField, $draftId)) {
                    $result = true;
                }
            }
        } catch (\Exception $e) {
        }
        return $result;
    }

    /**
     * @return WishlistItemModel
     */
    public function getWishlistItemModel(): WishlistItemModel
    {
        return $this->wishlistItemModel;
    }

    /**
     * @return CartModel
     */
    public function getCartItemModel(): CartModel
    {
        return $this->cartModel;
    }
}
