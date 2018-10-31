<?php

namespace Rissc\Printformer\Plugin\Checkout\CustomerData;

use Magento\Checkout\CustomerData\DefaultItem as SubjectDefaultItem;
use Magento\Quote\Api\Data\CartItemInterface;
use Rissc\Printformer\Helper\Api\Url;
use Rissc\Printformer\Helper\Config;
use Rissc\Printformer\Helper\Media;

class DefaultItem
{
    /**
     * @var Url
     */
    protected $urlHelper;

    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @var Media
     */
    protected $mediaHelper;

    /**
     * DefaultItem constructor.
     * @param Url $urlHelper
     * @param Config $configHelper
     * @param Media $mediaHelper
     */
    public function __construct(
        Url $urlHelper,
        Config $configHelper,
        Media $mediaHelper
    ) {
        $this->urlHelper = $urlHelper;
        $this->configHelper = $configHelper;
        $this->mediaHelper = $mediaHelper;
    }

    /**
     * @param SubjectDefaultItem $defaultItem
     * @param \Closure $proceed
     * @param CartItemInterface $item
     * @return mixed
     */
    public function aroundGetItemData(SubjectDefaultItem $defaultItem, \Closure $proceed, CartItemInterface $item)
    {
        $result = $proceed($item);
        $draftId = $item->getPrintformerDraftid();
        if ($draftId && $this->configHelper->isUseImagePreview()) {
            if($this->configHelper->isV2Enabled()) {
                $result['product_image']['src'] = $this->mediaHelper->getImageUrl($item->getPrintformerDraftid());
            } else {
                $result['product_image']['src'] = $this->urlHelper->getThumbnail($item->getPrintformerDraftid());
            }
        }
        return $result;
    }
}