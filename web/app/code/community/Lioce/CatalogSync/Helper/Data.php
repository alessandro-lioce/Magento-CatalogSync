<?php
	class Lioce_CatalogSync_Helper_Data extends Mage_Core_Helper_Abstract
	{
		public function setCategories($row, $product)
		{
			$category_ids = $row->category_ids;
			if ($row->insert_into_parent_categories)
			{
				$category_ids = array();
				foreach (explode(',', $row->category_ids) as $category_id)
				{
					$category = Mage::getModel('catalog/category')->load($category_id);
					foreach ($category->getPathIds() as $id)
					{
						$category_ids[$id] = $id;
					}
				}
				$category_ids = implode(',', $category_ids);
			}
			$product->setCategoryIds($category_ids);
		}
		
		public function setWebsites($row, $product)
		{
			$website_ids = $row->website_ids;
			if ($website_ids == 0)
			{
				$websites = Mage::app()->getWebsites(false, false);
				$website_ids = array();
				foreach ($websites as $w)
				{
					$website_ids[] = $w->getId();
				}
			}
			else
			{
				$website_ids = explode(',', $website_ids);
			}
			
			$product->setWebsiteIds($website_ids);
		}
		
		public function setUrlKey($row, $product)
		{
			if (is_null($row->url_key))
			{
				$product_url = Mage::getModel('catalog/product_url');
				$url_key = $product_url->formatUrlKey($row->name);
			}
			else $url_key = $row->url_key;
			$product->setData('url_key', $url_key);
		}
		
		public function addImage($row, $product)
		{
			$images = Mage::getModel("catalog/product_attribute_media_api")->items($product->getId());
			if (count($images) > 0) return;
			else
			{
				$filename = basename($row->image_url);
				$save_path = $_SERVER['DOCUMENT_ROOT'].'/media/tmp/';
				
				$ch = curl_init();
				curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_URL, $row->image_url);
				$content = curl_exec($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				
				if ($http_code == 200)
				{
					$imghandle = fopen($save_path.$filename, 'w');
					if ($imghandle === false) throw new Exception('errore fopen '.$save_path.$filename);
					fwrite($imghandle, $content);
					fclose($imghandle);
				
					$visibility = array (
						'thumbnail',
						'small_image',
						'image'
					);
					$product->addImageToMediaGallery($save_path.$filename, $visibility, true, false);
					$product->save();
				}
				else
				{
					throw new Exception("http code: ".$http_code.", l'immagine ".$record['path']." non esiste");
				}
			}
		}
	
		public function add($row)
		{
			$product = Mage::getModel('catalog/product');
			$product->setStoreId(0);
			$product->setHasOptions('0');
			$product->setRequiredOptions('0');
			$product->setTypeId('simple');
			$product->setCreatedAt(strtotime('now'));
			
			$this->setCategories($row, $product);
			$this->setWebsites($row, $product);
			$this->setUrlKey($row, $product);
			
			$attribute_codes = array(
				'sku',
				'status',
				'attribute_set_id',
				'price',
				'special_price',
				'cost',
				'name',
				'meta_title',
				'meta_keyword',
				'meta_description',
				'description',
				'short_description',
				'visibility',
				'tax_class_id',
				'weight'
			);
			foreach ($row as $attribute_code => $value)
			{
				if (!in_array($attribute_code, $attribute_codes) or is_null($value)) continue;
				$product->setData($attribute_code, $value);
			}
			
			$product->save();
			
			$product = Mage::getModel('catalog/product')->load($product->getId());
			$this->addImage($row, $product);
			
			//$product->getResource()->save($product); //$product->save();
			
			$product = Mage::getModel('catalog/product')->load($product->getId());
			$stockItem = $product->getStockItem();
			$stockItem->setData('is_in_stock', 1);
			$stockItem->setData('stock_id', 1);
			$stockItem->setData('manage_stock', 1);
			$stockItem->setData('use_config_manage_stock', 1);
			$stockItem->setData('qty', 1);
			$stockItem->save();

			//va dopo setProductAttributes perche' usano il prezzo che e' valorizzato in quel metodo
			//va dopo il salva per le traduzioni
			$this->setStoresProductInfo($row, $product);
			return $product->getId();
		}
		
		public function setStoresProductInfo($row, $product)
		{
			$store = Mage::app()->getStore();
			echo 'admin id ? '.$store->getId().'<br>';
			$admin_store_currency_code = $store->getCurrentCurrencyCode();
			$current_store_id = $store->getId();
			$current_locale = $store->getConfig('general/locale/code');
			//echo 'prev '.$store->getCode().' '.$store->getId().' '.$current_locale.'<br>';
			
			$default_price = $product->getPrice();
			
			$default_special_price = NULL;
			if ($product->hasSpecialPrice()) $default_special_price = $product->getSpecialPrice();
			
			$default_cost = NULL;
			if ($product->hasCost()) $default_cost = $product->getCost();
			
			$default_attributes = array('status');
			echo '<hr />';
			foreach (Mage::app()->getStores() as $store)
			{
				$currency_code = $store->getCurrentCurrencyCode();
				
				$price = Mage::helper('directory')->currencyConvert($default_price, $admin_store_currency_code, $currency_code);
				if (Mage::getStoreConfig('catalog/lioce_catalogsync/round_price_excess_fifty_cent')) $price = round(ceil(round($price, 2) / 0.5) * 0.5, 2); //arrotonda a 50 cent per eccesso
				
				$product
					->setStoreId($store->getId())
					->setPrice($price)
					;
				
				if (!is_null($default_special_price))
				{
					$special_price = Mage::helper('directory')->currencyConvert($default_special_price, $admin_store_currency_code, $currency_code);
					if (Mage::getStoreConfig('catalog/lioce_catalogsync/round_price_excess_fifty_cent')) $special_price = round(ceil(round($special_price, 2) / 0.5) * 0.5, 2); //arrotonda a 50 cent per eccesso
					$product->setSpecialPrice($special_price);
				}
				
				if (!is_null($default_cost))
				{
					$cost = Mage::helper('directory')->currencyConvert($default_cost, $admin_store_currency_code, $currency_code);
					$product->setCost($cost);
				}
				
				echo '<hr />'.$store->getCode().'<br>';
				/*
				echo $store->getCode().'<br>';
				echo 'price: '.$default_price.' '.$price.' '.$currency_code.'<br>';
				echo 'special_price: '.$default_special_price.' '.$special_price.' '.$currency_code.'<br>';
				echo 'cost: '.$default_cost.' '.$cost.' '.$currency_code.'<br>';
				*/

				
				
				/*
				echo $product->getSpecialPrice().' '.$product->getFinalPrice().'<br>';
				echo '<hr />';
				continue;
				*/
				
				Mage::app()->setCurrentStore($store->getId());
				$locale = $store->getConfig('general/locale/code');
				Mage::app()->getTranslator()->setLocale($locale);
				Mage::app()->getTranslator()->init('frontend', true);
				
				//echo 't locale '.Mage::app()->getTranslator()->getLocale().'<br>';
				//echo 'store_id: '.Mage::app()->getStore()->getId().' '.$store->getCode().' '.$locale.'-<br>';
				
				/*
				$keys = $this->createKeywords($product);
				$desc = $this->createDescription($product);
				$short_desc = $this->createShortDescription($product);
				$title = $this->createTitle($product);
				*/
				$title = $row->meta_title;
				$keys = $row->meta_keyword;
				$meta_description = $row->meta_description;
				$short_desc = $row->short_description;
				$desc = $row->description;

				Mage::app()->setCurrentStore($current_store_id);
				
				$product
					->setMetaTitle($title)
					->setMetaKeyword($keys)
					->setMetaDescription($meta_description)
					->setShortDescription($short_desc)
					->setDescription($desc)
					;
					
				foreach ($default_attributes as $default_attribute)
				{
					$product->setData($default_attribute, false);
				}
					
				//$product->getResource()->save($product); //
				$product->save();
			}
			//echo $current_locale.'--<br>';
			Mage::app()->getTranslator()->setLocale($current_locale);
			Mage::app()->getTranslator()->init('frontend', true);
			//die();
		}
	}
?>