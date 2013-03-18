<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Model;

use Isotope\Isotope;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Product\Standard as StandardProduct;


/**
 * Class Collection
 *
 * Provide methods to handle Isotope product collections.
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 */
abstract class ProductCollection extends \Model
{

    /**
     * Name of the current table
     * @var string
     */
    protected static $strTable = 'tl_iso_collection';

    /**
     * Name of the child table
     * @var string
     */
    protected static $ctable = 'tl_iso_collection_product';

    /**
     * Define if data should be threaded as "locked", eg. not apply discount rules to product prices
     * @var boolean
     */
    protected $blnLocked = false;

    /**
     * Cache all products for speed improvements
     * @var array
     */
    protected $arrProducts;

    /**
     * Cache all surcharges for speed improvements
     * @var array
     */
    protected $arrSurcharges;

    /**
     * Shipping object if shipping module is set in product collection
     * @var object
     */
    protected $Shipping;

    /**
     * Payment object if payment module is set in product collection
     * @var object
     */
    protected $Payment;

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'iso_invoice';

    /**
     * Configuration
     * @var array
     */
    protected $arrSettings = array();

    /**
     * Record has been modified
     * @var boolean
     */
    protected $blnModified = false;


    /**
     * Initialize the object
     */
    public function __construct(\Database\Result $objResult=null)
    {
        parent::__construct($objResult);

        if ($objResult !== null) {
            $this->arrSettings = deserialize($this->arrData['settings'], true);
        }

        $this->arrData['type'] = substr(get_called_class(), strrpos(get_called_class(), '\\')+1);

        // Do not use __destruct, because Database object might be destructed first (see http://github.com/contao/core/issues/2236)
        if (!$this->blnLocked) {
            register_shutdown_function(array($this, 'saveDatabase'));
        }

        $this->Isotope = \System::importStatic('Isotope\Isotope');
    }


    /**
     * Shutdown function to save data if modified
     */
    public function saveDatabase()
    {
        $this->save();
    }


    /**
     * Return data
     * @param string
     * @return mixed
     */
    public function __get($strKey)
    {
        if (!isset($this->arrCache[$strKey]))
        {
            if ($this->blnLocked && array_key_exists($strKey, $this->arrData)) {

                return deserialize($this->arrData[$strKey]);

            } elseif ($this->blnLocked && array_key_exists($strKey, $this->arrSettings)) {

                return deserialize($this->arrSettings[$strKey]);
            }

            switch ($strKey)
            {
                case 'table':
                    return static::$strTable;
                    break;

                case 'ctable':
                    return  static::$ctable;
                    break;

                case 'id':
                case 'pid':
                    return (int) $this->arrData[$strKey];
                    break;

                case 'Shipping':
                case 'Payment':
                    return $this->$strKey;
                    break;

                case 'requiresShipping':
                    $this->arrCache[$strKey] = false;
                    $arrProducts = $this->getProducts();

                    foreach ($arrProducts as $objProduct) {

                        if (!$objProduct->shipping_exempt) {
                            $this->arrCache[$strKey] = true;
                        }
                    }
                    break;

                case 'requiresPayment':
                    return $this->grandTotal > 0 ? true : false;
                    break;

                case 'items':
                    $this->arrCache[$strKey] = \Database::getInstance()->execute("SELECT SUM(quantity) AS items FROM " . static::$ctable . " WHERE pid={$this->id}")->items;
                    break;

                case 'products':
                    $this->arrCache[$strKey] = \Database::getInstance()->execute("SELECT COUNT(*) AS items FROM " . static::$ctable . " WHERE pid={$this->id}")->items;
                    break;

                case 'lastAdded':
                    // getProducts() will set the cache key/value.
                    // Only if the function has never been called, this will be triggered
                    $this->getProducts('', true);
                    break;

                case 'subTotal':
                    $fltTotal = 0;
                    $arrProducts = $this->getProducts();

                    foreach ($arrProducts as $objProduct) {

                        $varPrice = $objProduct->total_price;

                        if ($varPrice !== null) {
                            $fltTotal += $varPrice;
                        }
                    }

                    $this->arrCache[$strKey] = $fltTotal;
                    break;

                case 'taxFreeSubTotal':
                    $fltTotal = 0;
                    $arrProducts = $this->getProducts();

                    foreach ($arrProducts as $objProduct) {
                        $varPrice = $objProduct->tax_free_total_price;

                        if ($varPrice !== null) {
                            $fltTotal += $varPrice;
                        }
                    }

                    $this->arrCache[$strKey] = $fltTotal;
                    break;

                case 'grandTotal':
                    $fltTotal = $this->subTotal;
                    $arrSurcharges = $this->getSurcharges();

                    foreach ($arrSurcharges as $objSurcharge)
                    {
                        if ($objSurcharge->add !== false)
                        {
                            $fltTotal += $objSurcharge->total_price;
                        }
                    }

                    $this->arrCache[$strKey] = $fltTotal > 0 ? Isotope::getInstance()->roundPrice($fltTotal) : 0;
                    break;

                default:
                    if (array_key_exists($strKey, $this->arrData)) {

                        return deserialize($this->arrData[$strKey]);

                    } else {

                        return deserialize($this->arrSettings[$strKey]);

                    }
                    break;
            }
        }

        return $this->arrCache[$strKey];
    }


    /**
     * Set data
     * @param string
     * @param mixed
     */
    public function __set($strKey, $varValue)
    {
        $this->arrCache = array();

        if ($strKey == 'Shipping' || $strKey == 'Payment')
        {
            $this->$strKey = $varValue;
            $this->arrSurcharges = null;
        }
        elseif ($strKey == 'modified')
        {
            $this->blnModified = (bool) $varValue;
            $this->arrProducts = null;
            $this->arrSurcharges = null;
        }

        // We dont want $this->import() objects to be in arrSettings
        elseif (is_object($varValue))
        {
            $this->$strKey = $varValue;
        }

        // If there is a database field for that key, we store it there
        elseif (array_key_exists($strKey, $this->arrData) || \Database::getInstance()->fieldExists($strKey, static::$strTable))
        {
            $this->arrData[$strKey] = $varValue;
            $this->blnModified = true;
        }

        // Everything else goes into arrSettings and is serialized
        else
        {
            if ($varValue === null) {
                unset($this->arrSettings[$strKey]);
            } else {
                $this->arrSettings[$strKey] = $varValue;
            }

            $this->blnModified = true;
        }
    }


    /**
     * Check whether a property is set
     * @param string
     * @return boolean
     */
    public function __isset($strKey)
    {
        if (isset($this->arrData[$strKey]) || isset($this->arrSettings[$strKey])) {

            return true;
        }

        return false;
    }


    /**
     * Return true if collection is locked
     * @return bool
     */
    public function isLocked()
    {
        return $this->blnLocked;
    }

    /**
     * Return boolean wether collection has payment
     * @return bool
     */
    public function hasPayment()
    {
        return (is_object($this->Payment) ? true : false);
    }

    /**
     * Return boolean wether collection has shipping
     * @return bool
     */
    public function hasShipping()
    {
        return (is_object($this->Shipping) ? true : false);
    }


    /**
     * Load settings from database field
     * @param object
     * @param string
     * @param string
     */
    public function setRow(array $arrData)
    {
        parent::setRow($arrData);
        $this->arrSettings = deserialize($arrData['settings'], true);
    }


    /**
     * Update database with latest product prices and store settings
     * @param boolean
     * @return integer
     */
    public function save($blnForceInsert=false)
    {
        if ($this->blnModified) {
            $this->arrData['tstamp'] = time();
            $this->arrData['settings'] = serialize($this->arrSettings);
        }

        $arrProducts = $this->getProducts();

        if (is_array($arrProducts) && !empty($arrProducts))
        {
            foreach ($arrProducts as $objProduct)
            {
                \Database::getInstance()->prepare("UPDATE " . static::$ctable . " SET price=?, tax_free_price=? WHERE id=?")->execute($objProduct->price, $objProduct->tax_free_price, $objProduct->collection_id);
            }
        }

        // !HOOK: additional functionality when saving a collection
        if (isset($GLOBALS['ISO_HOOKS']['saveCollection']) && is_array($GLOBALS['ISO_HOOKS']['saveCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['saveCollection'] as $callback)
            {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($this);
            }
        }

        if ($this->blnModified || $blnForceInsert) {
            parent::save();
        }

        return $this;
    }


    /**
     * Also delete child table records when dropping this collection
     * @return integer
     */
    public function delete()
    {
        // !HOOK: additional functionality when deleting a collection
        if (isset($GLOBALS['ISO_HOOKS']['deleteCollection']) && is_array($GLOBALS['ISO_HOOKS']['deleteCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['deleteCollection'] as $callback)
            {
                $this->import($callback[0]);
                $blnRemove = $this->$callback[0]->$callback[1]($this);

                if ($blnRemove === false)
                {
                    return 0;
                }
            }
        }

        $intAffectedRows = parent::delete();

        if ($intAffectedRows > 0) {
            \Database::getInstance()->prepare("DELETE FROM " . static::$ctable . " WHERE pid=?")->execute($this->id);
        }

        $this->arrCache = array();
        $this->arrProducts = null;
        $this->arrSurcharges = null;

        return $intAffectedRows;
    }


    /**
     * Delete all products in the collection
     */
    public function purge()
    {
        $arrProducts = $this->getProducts();

        foreach ($arrProducts as $objProduct) {
            $this->deleteProduct($objProduct);
        }
    }


    /**
     * Fetch products from database
     * @param string
     * @param boolean
     * @return array
     */
    public function getProducts($strTemplate='', $blnNoCache=false)
    {
        if (!is_array($this->arrProducts) || $blnNoCache)
        {
            $objDatabase = \Database::getInstance();

            $this->arrProducts = array();
            $this->arrCache['lastAdded'] = 0;
            $lastAdded = 0;

            $objItems = $objDatabase->prepare("SELECT * FROM " . static::$ctable . " WHERE pid=?")->executeUncached($this->id);

            while ($objItems->next())
            {
                $objProductData = $objDatabase->prepare(StandardProduct::getSelectStatement() . "
                                                            WHERE p1.language='' AND p1.id=?")
                                              ->limit(1)
                                              ->execute($objItems->product_id);

                $strClass = $GLOBALS['ISO_PRODUCT'][$objProductData->product_class]['class'];
                $arrData = array('sku'=>$objItems->sku, 'name'=>$objItems->name, 'price'=>$objItems->price, 'tax_free_price'=>$objItems->tax_free_price);

                if ($objProductData->numRows && $strClass != '')
                {
                    try
                    {
                        $arrData = $this->blnLocked ? array_merge($objProductData->row(), $arrData) : $objProductData->row();
                        $objProduct = new $strClass($arrData, deserialize($objItems->options), $this->blnLocked, $objItems->quantity);
                    }
                    catch (Exception $e)
                    {
                        $objProduct = new StandardProduct($arrData, deserialize($objItems->options), $this->blnLocked, $objItems->quantity);
                    }
                }
                else
                {
                    $objProduct = new StandardProduct($arrData, deserialize($objItems->options), $this->blnLocked, $objItems->quantity);
                }

                // Remove product from collection if it is no longer available
                if (!$objProduct->isAvailable())
                {
                    $objProduct->collection_id = $objItems->id;
                    $this->deleteProduct($objProduct);
                    continue;
                }

                $objProduct->collection_id = $objItems->id;
                $objProduct->tax_id = $objItems->tax_id;
                $objProduct->reader_jumpTo_Override = $objItems->href_reader;

                if ($objItems->tstamp > $lastAdded)
                {
                    $this->arrCache['lastAdded'] = $objItems->id;
                    $lastAdded = $objItems->tstamp;
                }

                $this->arrProducts[] = $objProduct;
            }
        }

        if (strlen($strTemplate))
        {
            $objTemplate = new \Isotope\Template($strTemplate);

            $objTemplate->products = $this->arrProducts;
            $objTemplate->surcharges = \Isotope\Frontend::formatSurcharges($this->getSurcharges());
            $objTemplate->subTotalLabel = $GLOBALS['TL_LANG']['MSC']['subTotalLabel'];
            $objTemplate->subTotalPrice = Isotope::formatPriceWithCurrency($this->subTotal, false);
            $objTemplate->grandTotalLabel = $GLOBALS['TL_LANG']['MSC']['grandTotalLabel'];
            $objTemplate->grandTotalPrice = Isotope::formatPriceWithCurrency($this->grandTotal, false);
            $objTemplate->collection = $this;

            return $objTemplate->parse();
        }

        return $this->arrProducts;
    }


    /**
     * Add a product to the collection
     * @param object The product object
     * @param integer How many products to add
     * @return integer ID of database record added/updated
     */
    public function addProduct(IsotopeProduct $objProduct, $intQuantity)
    {
        // !HOOK: additional functionality when adding product to collection
        if (isset($GLOBALS['ISO_HOOKS']['addProductToCollection']) && is_array($GLOBALS['ISO_HOOKS']['addProductToCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['addProductToCollection'] as $callback)
            {
                $this->import($callback[0]);
                $intQuantity = $this->$callback[0]->$callback[1]($objProduct, $intQuantity, $this);
            }
        }

        if ($intQuantity == 0) {
            return false;
        }

        $time = time();
        $strType = substr(get_class($objProduct), strrpos(get_class($objProduct), '\\')+1);
        $this->modified = true;
        $objDatabase = \Database::getInstance();

        // Make sure collection is in DB before adding product
        if (!$this->blnRecordExists) {
            $this->save();
        }

        $objItem = $objDatabase->prepare("SELECT * FROM " . static::$ctable . " WHERE pid={$this->id} AND type='$strType' AND product_id={$objProduct->id} AND options=?")->limit(1)->execute(serialize($objProduct->getOptions(true)));

        if ($objItem->numRows)
        {
            if (($objItem->quantity + $intQuantity) < $objProduct->minimum_quantity)
    		{
        		$_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
        		$intQuantity = $objProduct->minimum_quantity - $objItem->quantity;
    		}

            $objDatabase->query("UPDATE " . static::$ctable . " SET tstamp=$time, quantity=(quantity+$intQuantity) WHERE id={$objItem->id}");

            return $objItem->id;
        }
        else
        {
            if ($intQuantity < $objProduct->minimum_quantity)
    		{
        		$_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
        		$intQuantity = $objProduct->minimum_quantity;
    		}

            $arrSet = array
            (
                'pid'               => $this->id,
                'tstamp'            => $time,
                'type'              => $strType,
                'product_id'        => (int) $objProduct->id,
                'sku'               => (string) $objProduct->sku,
                'name'              => (string) $objProduct->name,
                'options'           => $objProduct->getOptions(true),
                'quantity'          => (int) $intQuantity,
                'price'             => (float) $objProduct->price,
                'tax_free_price'    => (float) $objProduct->tax_free_price,
            );

            if ($objDatabase->fieldExists('href_reader', static::$ctable))
            {
                $arrSet['href_reader'] = $objProduct->href_reader;
            }

            $intInsertId = $objDatabase->prepare("INSERT INTO " . static::$ctable . " %s")->set($arrSet)->executeUncached()->insertId;

            return $intInsertId;
        }
    }


    /**
     * update a product in the collection
     * @param object The product object
     * @param array The property(ies) to adjust
     * @return integer ID of database record added/updated
     */
    public function updateProduct(IsotopeProduct $objProduct, $arrSet)
    {
        if (!$objProduct->collection_id) {
            return false;
        }

        // !HOOK: additional functionality when updating a product in the collection
        if (isset($GLOBALS['ISO_HOOKS']['updateProductInCollection']) && is_array($GLOBALS['ISO_HOOKS']['updateProductInCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['updateProductInCollection'] as $callback)
            {
                $this->import($callback[0]);
                $arrSet = $this->$callback[0]->$callback[1]($objProduct, $arrSet, $this);

                if (is_array($arrSet) && empty($arrSet))
                {
                    return false;
                }
            }
        }

        // Quantity set to 0, delete product
        if (isset($arrSet['quantity']) && $arrSet['quantity'] == 0) {
            return $this->deleteProduct($objProduct);
        }

        if (isset($arrSet['quantity']) && $arrSet['quantity'] < $objProduct->minimum_quantity) {
    		$_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
    		$arrSet['quantity'] = $objProduct->minimum_quantity;
		}

        // Modify timestamp when updating a product
        $arrSet['tstamp'] = time();

        $intAffectedRows = \Database::getInstance()->prepare("UPDATE " . static::$ctable . " %s WHERE id={$objProduct->collection_id}")
                                                   ->set($arrSet)
                                                   ->executeUncached()
                                                   ->affectedRows;

        if ($intAffectedRows > 0) {
            $this->modified = true;

            return true;
        }

        return false;
    }


    /**
     * Delete a product in the collection
     * @param object
     * @param boolean force deleting the product even if the collection is locked
     * @return boolean
     */
    public function deleteProduct(IsotopeProduct $objProduct)
    {
        if (!$objProduct->collection_id) {
            return false;
        }

        // !HOOK: additional functionality when a product is removed from the collection
        if (isset($GLOBALS['ISO_HOOKS']['deleteProductFromCollection']) && is_array($GLOBALS['ISO_HOOKS']['deleteProductFromCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['deleteProductFromCollection'] as $callback)
            {
                $this->import($callback[0]);
                $blnRemove = $this->$callback[0]->$callback[1]($objProduct, $this);

                if ($blnRemove === false)
                {
                    return false;
                }
            }
        }

        $this->modified = true;
        \Database::getInstance()->query("DELETE FROM " . static::$ctable . " WHERE id={$objProduct->collection_id}");

        return true;
    }


    public function getSurcharges()
    {
        if (null === $this->arrSurcharges) {
            $this->arrSurcharges = $this->isLocked() ? ProductCollectionSurcharge::findByPid($this->id) : ProductCollectionSurcharge::findForCollection($this);
        }

        return $this->arrSurcharges;
    }


    /**
     * Transfer products from another collection to this one (e.g. Cart to Order)
     * @param object
     * @param boolean
     * @return array
     */
    public function transferFromCollection(IsotopeProductCollection $objCollection, $blnDuplicate=true)
    {
        if (!$this->blnRecordExists) {
            $this->save(true);
        }

        // Make sure database table has the latest prices
        $objCollection->save();

        $objDatabase = \Database::getInstance();

        $time = time();
        $arrIds = array();
        $objOldItems = $objDatabase->execute("SELECT * FROM {$objCollection->ctable} WHERE pid={$objCollection->id}");

        while ($objOldItems->next())
        {
            $blnTransfer = true;
            $objNewItems = $objDatabase->prepare("SELECT * FROM " . static::$ctable . " WHERE pid={$this->id} AND product_id={$objOldItems->product_id} AND options=?")->execute($objOldItems->options);

            // !HOOK: additional functionality when adding product to collection
            if (isset($GLOBALS['ISO_HOOKS']['transferCollection']) && is_array($GLOBALS['ISO_HOOKS']['transferCollection']))
            {
                foreach ($GLOBALS['ISO_HOOKS']['transferCollection'] as $callback)
                {
                    $this->import($callback[0]);
                    $blnTransfer = $this->$callback[0]->$callback[1]($objOldItems, $objNewItems, $objCollection, $this, $blnTransfer);
                }
            }

            if (!$blnTransfer)
            {
                continue;
            }

            // Product exists in target table. Increase amount.
            if ($objNewItems->numRows)
            {
                $objDatabase->query("UPDATE " . static::$ctable . " SET tstamp=$time, quantity=(quantity+{$objOldItems->quantity}) WHERE id={$objNewItems->id}");
                $arrIds[$objOldItems->id] = $objNewItems->id;
            }

            // Product does not exist in this collection, we don't duplicate and are on the same table. Simply change parent id.
            elseif (!$objNewItems->numRows && !$blnDuplicate && static::$ctable == $objCollection->ctable)
            {
                $objDatabase->query("UPDATE " . static::$ctable . " SET tstamp=$time, pid={$this->id} WHERE id={$objOldItems->id}");
                $arrIds[$objOldItems->id] = $objOldItems->id;
            }

            // Duplicate all existing rows to target table
            else
            {
                $arrSet = array('pid'=>$this->id, 'tstamp'=>$time);

                foreach ($objOldItems->row() as $k => $v)
                {
                    if (in_array($k, array('id', 'pid', 'tstamp')))
                    {
                        continue;
                    }

                    if ($objDatabase->fieldExists($k, static::$ctable))
                    {
                        $arrSet[$k] = $v;
                    }
                }

                $arrIds[$objOldItems->id] = $objDatabase->prepare("INSERT INTO " . static::$ctable . " %s")->set($arrSet)->executeUncached()->insertId;
            }
        }

        if (!empty($arrIds))
        {
            $this->modified = true;
        }

        // !HOOK: additional functionality when adding product to collection
        if (isset($GLOBALS['ISO_HOOKS']['transferredCollection']) && is_array($GLOBALS['ISO_HOOKS']['transferredCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['transferredCollection'] as $callback)
            {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($objCollection, $this, $arrIds);
            }
        }

        return $arrIds;
    }


    /**
     * Calculate the weight of all products in the cart in a specific weight unit
     * @param string
     * @return mixed
     */
    public function getShippingWeight($unit)
    {
        $arrWeights = array();
        $arrProducts = $this->getProducts();

        foreach ($arrProducts as $objProduct)
        {
            $arrWeight = deserialize($objProduct->shipping_weight, true);
            $arrWeight['value'] = $objProduct->quantity_requested * floatval($arrWeight['value']);

            $arrWeights[] = $arrWeight;
        }

        return Isotope::calculateWeight($arrWeights, $unit);
    }


    /**
     * Generate the collection using a template. Useful for PDF output
     * @param string
     * @param boolean
     * @return string
     */
    public function generate($strTemplate=null, $blnResetConfig=true)
    {
        if ($strTemplate)
        {
            $this->strTemplate = $strTemplate;
        }

        // Set global config to this collection (if available)
        if ($this->config_id > 0)
        {
            Isotope::overrideConfig($this->config_id);
        }

        // Load language files for the order
        if ($this->language != '')
        {
            $this->loadLanguageFile('default', $this->language);
        }

        $objTemplate = new \Isotope\Template($this->strTemplate);
        $objTemplate->setData($this->arrData);
        $objTemplate->logoImage = '';

        if (Isotope::getConfig()->invoiceLogo != '' && is_file(TL_ROOT . '/' . Isotope::getConfig()->invoiceLogo))
        {
            $objTemplate->logoImage = '<img src="' . TL_ROOT . '/' . Isotope::getConfig()->invoiceLogo . '" alt="" />';
        }

        $objTemplate->invoiceTitle = $GLOBALS['TL_LANG']['MSC']['iso_invoice_title'] . ' ' . $this->order_id . ' – ' . date($GLOBALS['TL_CONFIG']['datimFormat'], $this->date);

        $arrItems = array();
        $arrProducts = $this->getProducts();

        foreach ($arrProducts as $objProduct)
        {
            $arrItems[] = array
            (
                'raw'               => $objProduct->getData(),
                'options'           => $objProduct->getOptions(),
                'name'              => $objProduct->name,
                'quantity'          => $objProduct->quantity_requested,
                'price'             => $objProduct->formatted_price,
                'tax_free_price'    => $this->Isotope->formatPriceWithCurrency($objProduct->tax_free_price),
                'total'             => $objProduct->formatted_total_price,
                'tax_free_total'    => $this->Isotope->formatPriceWithCurrency($objProduct->tax_free_total_price),
                'tax_id'            => $objProduct->tax_id,
            );
        }

        $objTemplate->collection = $this;
        $objTemplate->config = Isotope::getConfig()->getData();
        $objTemplate->info = deserialize($this->checkout_info);
        $objTemplate->items = $arrItems;
        $objTemplate->raw = $this->arrData;
        $objTemplate->date = \System::parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $this->date);
        $objTemplate->time = \System::parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $this->date);
        $objTemplate->datim = \System::parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $this->date);
        $objTemplate->datimLabel = $GLOBALS['TL_LANG']['MSC']['datimLabel'];
        $objTemplate->subTotalPrice = Isotope::formatPriceWithCurrency($this->subTotal);
        $objTemplate->grandTotal = Isotope::formatPriceWithCurrency($this->grandTotal);
        $objTemplate->subTotalLabel = $GLOBALS['TL_LANG']['MSC']['subTotalLabel'];
        $objTemplate->grandTotalLabel = $GLOBALS['TL_LANG']['MSC']['grandTotalLabel'];

        $objTemplate->surcharges = \Isotope\Frontend::formatSurcharges($this->getSurcharges());
        $objTemplate->billing_label = $GLOBALS['TL_LANG']['MSC']['billing_address'];
        $objTemplate->billing_address = $this->billingAddress->generateText(Isotope::getConfig()->billing_fields);

        if (strlen($this->shipping_method))
        {
            $arrShippingAddress = deserialize($this->shipping_address);

            if (!is_array($arrShippingAddress) || $arrShippingAddress['id'] == -1)
            {
                $objTemplate->has_shipping = false;
                $objTemplate->billing_label = $GLOBALS['TL_LANG']['MSC']['billing_shipping_address'];
            }
            else
            {
                $objTemplate->has_shipping = true;
                $objTemplate->shipping_label = $GLOBALS['TL_LANG']['MSC']['shipping_address'];
                $objTemplate->shipping_address = $this->shippingAddress->generateText(Isotope::getConfig()->shipping_fields);
            }
        }

        // !HOOK: allow overriding of the template
        if (isset($GLOBALS['ISO_HOOKS']['generateCollection']) && is_array($GLOBALS['ISO_HOOKS']['generateCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['generateCollection'] as $callback)
            {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($objTemplate, $arrItems, $this);
            }
        }

        $strArticle = Isotope::getInstance()->call('replaceInsertTags', array($objTemplate->parse()));
        $strArticle = html_entity_decode($strArticle, ENT_QUOTES, $GLOBALS['TL_CONFIG']['characterSet']);
        $strArticle = \Controller::convertRelativeUrls($strArticle, '', true);

        // Remove form elements and JavaScript links
        $arrSearch = array
        (
            '@<form.*</form>@Us',
            '@<a [^>]*href="[^"]*javascript:[^>]+>.*</a>@Us'
        );

        $strArticle = preg_replace($arrSearch, '', $strArticle);

        // Handle line breaks in preformatted text
        $strArticle = preg_replace_callback('@(<pre.*</pre>)@Us', 'nl2br_callback', $strArticle);

        // Default PDF export using TCPDF
        $arrSearch = array
        (
            '@<span style="text-decoration: ?underline;?">(.*)</span>@Us',
            '@(<img[^>]+>)@',
            '@(<div[^>]+block[^>]+>)@',
            '@[\n\r\t]+@',
            '@<br /><div class="mod_article@',
            '@href="([^"]+)(pdf=[0-9]*(&|&amp;)?)([^"]*)"@'
        );

        $arrReplace = array
        (
            '<u>$1</u>',
            '<br />$1',
            '<br />$1',
            ' ',
            '<div class="mod_article',
            'href="$1$4"'
        );

        $strArticle = preg_replace($arrSearch, $arrReplace, $strArticle);

        // Set config back to default
        if ($blnResetConfig)
        {
            Isotope::resetConfig();
            \System::loadLanguageFile('default', $GLOBALS['TL_LANGUAGE']);
        }

        return $strArticle;
    }


    /**
     * Generate a PDF file and optionally send it to the browser
     * @param string
     * @param object
     * @param boolean
     */
    public function generatePDF($strTemplate=null, $pdf=null, $blnOutput=true)
    {
        if (!is_object($pdf))
        {
            // TCPDF configuration
            $l['a_meta_dir'] = 'ltr';
            $l['a_meta_charset'] = $GLOBALS['TL_CONFIG']['characterSet'];
            $l['a_meta_language'] = $GLOBALS['TL_LANGUAGE'];
            $l['w_page'] = 'page';

            // Include library
            require_once(TL_ROOT . '/system/config/tcpdf.php');
            require_once(TL_ROOT . '/plugins/tcpdf/tcpdf.php');

            // Prevent TCPDF from destroying absolute paths
            unset($_SERVER['DOCUMENT_ROOT']);

            // Create new PDF document
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true);

            // Set document information
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor(PDF_AUTHOR);

// @todo $objInvoice is not defined
//            $pdf->SetTitle($objInvoice->title);
//            $pdf->SetSubject($objInvoice->title);
//            $pdf->SetKeywords($objInvoice->keywords);

            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Set margins
            $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);

            // Set auto page breaks
            $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

            // Set image scale factor
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

            // Set some language-dependent strings
            $pdf->setLanguageArray($l);

            // Initialize document and add a page
            $pdf->AliasNbPages();

            // Set font
            $pdf->SetFont(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN);
        }

        // Start new page
        $pdf->AddPage();

        // Write the HTML content
        $pdf->writeHTML($this->generate($strTemplate, false), true, 0, true, 0);

        if ($blnOutput)
        {
            // Close and output PDF document
            // @todo $strInvoiceTitle is not defined
            $pdf->lastPage();
            $pdf->Output(standardize(ampersand($strInvoiceTitle, false), true) . '.pdf', 'D');

            // Stop script execution
            exit;
        }

        return $pdf;
    }


    /**
     * Make sure we only return results of the given model type
     */
    protected static function find(array $arrOptions)
    {
        // Convert to array if necessary
        $arrOptions['value'] = (array) $arrOptions['value'];
        if (!is_array($arrOptions['column']))
        {
            $arrOptions['column'] = array($arrOptions['column'].'=?');
        }

        $arrOptions['column'][] = 'type=?';
        $arrOptions['value'][] = substr(get_called_class(), strrpos(get_called_class(), '\\')+1);

        return parent::find($arrOptions);
    }
}