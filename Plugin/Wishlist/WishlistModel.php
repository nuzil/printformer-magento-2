<?php

namespace Rissc\Printformer\Plugin\Wishlist;

use Magento\Catalog\Model\Product as ProductModel;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\Wishlist;
use Rissc\Printformer\Helper as Helper;
use Rissc\Printformer\Helper\Session as SessionHelper;
use Rissc\Printformer\Helper\Product as productHelper;

class WishlistModel
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var SessionHelper
     */
    protected $sessionHelper;
    private productHelper $productHelper;

    /**
     * WishlistModel constructor.
     * @param Registry $registry
     * @param StoreManagerInterface $storeManager
     * @param SessionHelper $sessionHelper
     */
    public function __construct(
        Registry $registry,
        StoreManagerInterface $storeManager,
        SessionHelper $sessionHelper,
        ProductHelper $productHelper
    ) {
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->sessionHelper = $sessionHelper;
        $this->productHelper = $productHelper;
    }

    /**
     * @param Wishlist $subject
     * @param $product
     * @param null $buyRequest
     * @param bool $forciblySetQty
     */
    public function beforeAddNewItem(
        Wishlist $subject,
        ProductModel $product,
        $buyRequest = null,
        $forciblySetQty = false
    ) {
        if (isset($buyRequest) && $buyRequest->getStoreId()) {
            $storeId = $buyRequest->getStoreId();
        } else {
            $storeId = $this->storeManager->getStore()->getId();
        }

        if (is_string($buyRequest)) {
            $buyRequest = new \Magento\Framework\DataObject(unserialize($buyRequest));
        } elseif (is_array($buyRequest)) {
            $buyRequest = new \Magento\Framework\DataObject($buyRequest);
        } elseif (!$buyRequest instanceof \Magento\Framework\DataObject) {
            $buyRequest = new \Magento\Framework\DataObject();
        }

        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
            $product = $this->productHelper->getChildProduct($product, $buyRequest->getSuperAttribute());
        }

        $productId = $product->getId();
        $drafts = $this->sessionHelper->getDraftIdsByProductId($productId);
        if (!empty($drafts[$productId])) {
            foreach ($drafts[$productId] as $draftKey => $draftValue) {
                $printformerProductId = $draftKey;
                if ($buyRequest->getData('_processing_params')) {
                    $draftId = $buyRequest
                        ->getData('_processing_params')
                        ->getData('current_config')
                        ->getData($this->productHelper::COLUMN_NAME_DRAFTID);
                    if ($draftId) {
                        $buyRequest->setData($this->productHelper::COLUMN_NAME_DRAFTID, $draftId);
                    }
                } elseif (!empty($draftValue)) {
                    $draftIdsStored = $buyRequest->getData($this->productHelper::COLUMN_NAME_DRAFTID);
                    if (!empty($draftIdsStored)){
                        $draftIds = $draftIdsStored . ',' . $draftValue;
                    } else {
                        $draftIds = $draftValue;
                    }
                    $buyRequest->setData(
                        $this->productHelper::COLUMN_NAME_DRAFTID,
                        $draftIds
                    );
                    $this->sessionHelper->unsetSessionUniqueIdByDraftId($draftValue);
                    $this->sessionHelper->unsetDraftId($productId, $printformerProductId, $storeId);
                }
            }
        }
    }

    /**
     * @param Wishlist $subject
     * @param $result
     * @return mixed
     */
    public function afterAddNewItem(Wishlist $subject, $result)
    {
        if (!is_string($result)) {
            $value = $result->getBuyRequest()->getData($this->productHelper::COLUMN_NAME_DRAFTID);
            $option = array(
                'code' => $this->productHelper::COLUMN_NAME_DRAFTID,
                'value' => $value,
                'product_id' => $result->getProductId()
            );
            $result->addOption($option)->saveItemOptions();
            $this->registry->register(
                Helper\Config::REGISTRY_KEY_WISHLIST_NEW_ITEM_ID,
                $result->getData('wishlist_item_id')
            );
        }
        return $result;
    }
}
