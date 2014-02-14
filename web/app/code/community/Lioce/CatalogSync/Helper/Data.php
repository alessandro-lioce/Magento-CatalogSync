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
			if (empty($row->url_key))
			{
				$product_url = Mage::getModel('catalog/product_url');
				$url_key = $product_url->formatUrlKey($product->getName());
			}
			else $url_key = $row->url_key;
			$product->setData('url_key', $url_key);
		}
		
		public function addImage($row, $product)
		{
			$product = Mage::getModel('catalog/product')->load($product->getId());
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
		
		public function update($row, $product)
		{
			$this->setCategories($row, $product);
			$this->setWebsites($row, $product);
			
			$attribute_codes = array(
				'sku',
				'status',
				'attribute_set_id',
				'price',
				'special_price',
				'cost',
				'visibility',
				'tax_class_id',
				'weight'
			);
			foreach ($row as $attribute_code => $value)
			{
				if (!in_array($attribute_code, $attribute_codes) or empty($value)) continue;
				$product->setData($attribute_code, $value);
			}
			
			$product->save();
			
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
		}
	
		public function add($row)
		{
			$product = Mage::getModel('catalog/product');
			$product->setStoreId(0);
			$product->setHasOptions('0');
			$product->setRequiredOptions('0');
			$product->setTypeId('simple');
			$product->setCreatedAt(strtotime('now'));
			
			$this->update($row, $product);
			
			return $product->getId();
		}
		
		public function compose($product, $text, $params)
		{
			$text = $this->__($text);
			if (!empty($params))
			{
				$values = array();
				$attribute_codes = explode(',', $params);
				foreach ($attribute_codes as $attribute_code)
				{
					$attribute_id = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', $attribute_code);
					if (empty($attribute_id)) throw new Exception("attribute ".$attribute_code." not exists");
					$attribute = Mage::getModel('eav/entity_attribute')->load($attribute_id);
					$input = $attribute->getFrontendInput();
					if ($input == 'select')
					{
						$values[] = $product->getAttributeText($attribute_code);
					}
					else if ($input == 'text')
					{
						$values[] = $product->getData($attribute_code);
					}
					else throw new Exception('compose() frontend input "'.$type.'" unknown');
				}
				//echo $text.'-<br>';
				$text = vsprintf($text, $values);
				//echo 'caso 1: '.$text.'<br>';
				return $text;
			}
			else
			{
				//echo 'caso 2: '.$text.'<br>';
				return $text;
			}
		}
		
		/**
		* Restituisce un array di int indicizzato con l'etichetta dell'opzione dell'attributo e come valore il suo id
		* @return array<int>
		* @throws Exception se l'attributo ha 2 opzioni con la stessa etichetta
		*/
		public function getProductAttributeOptions($attribute_code)
		{
			$attribute_id = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', $attribute_code);
			if (empty($attribute_id)) throw new Exception("l'attributo ".$attribute_code." non esiste");
			
			$attribute = Mage::getModel('catalog/resource_eav_attribute')->load($attribute_id);
			$attribute_options = $attribute ->getSource()->getAllOptions(false);
			
			$options = array();
			$label_count = array();
			foreach ($attribute_options as $option)
			{
				if (!isset($label_count[$option['label']])) $label_count[$option['label']] = 0;
				$options[$option['label']] = $option['value'];
				$label_count[$option['label']] += 1;
				
				if ($label_count[$option['label']] > 1) throw new Exception("l'attributo ".$attribute_code." ha due opzioni con la stessa etichetta ".$option['label']);
			}
			
			return $options;
		}
		
		/**
		* Restituisce il valore dell'opzione con etichetta $label dell'attributo $attribute_code
		* @return int
		*/
		public function getProductAttributeOptionValueByLabel($attribute_code, $label)
		{
			$options = $this->getProductAttributeOptions($attribute_code);
			$value = $options[$label];
			return $value;
		}
		
		public function setCustomAttributes($row, $product)
		{
			if (empty($row->custom_attribute_json)) return;
			$attributes = Zend_Json::decode($row->custom_attribute_json);
			foreach ($attributes as $attribute)
			{
				/*
					$attribute = array(
						'attribute_code'=>string,
						'input'=>'text',
						'value'=>string
					);
					
					$attribute = array(
						'attribute_code'=>string
						'input'=>'select',
						'type'=>string['id','admin_store_label'],
						'value'=>array<mixed>,mixed
					);
				*/
				if ($attribute['input'] == 'select')
				{
					if ($attribute['type'] == 'id')
					{
						$value = $attribute['value'];
					}
					else if ($attribute['type'] == 'admin_store_label')
					{
						if (is_array($attribute['value']))
						{
							$value = array();
							foreach ($attribute['value'] as $admin_store_label)
							{
								$value[] = $this->getProductAttributeOptionValueByLabel($attribute['attribute_code'], $admin_store_label);
							}
						}
						else $value = $attribute['value'];
					}
				}
				else if ($attribute['input'] == 'text')
				{
					$value = $this->__($attribute['value']);
				}
				else
				{
					throw new Exception("attribute_type ".$attribute['type']." non valido per la colonna ".$attribute['attribute_code']);
				}
				
				$product->setData($attribute['attribute_code'], $value);
			}
		}
		
		public function translateInfo($row, $product, $store)
		{
			$locale = $store->getConfig('general/locale/code');
			Mage::app()->getTranslator()->setLocale($locale);
			Mage::app()->getTranslator()->init('frontend', true);
			
			$this->setCustomAttributes($row, $product);
			
			//echo 't locale '.Mage::app()->getTranslator()->getLocale().'<br>';
			//echo 'store_id: '.$store->getId().' '.$store->getCode().' '.$locale.'-<br>';
			
			$name = $this->compose($product, $row->name, $row->attribute_code_list_for_name);
			$product->setName($name);
			$this->setUrlKey($row, $product);
			
			if (!empty($row->meta_title)) $meta_title = $this->compose($product, $row->meta_title, $row->attribute_code_list_for_meta_keyword);
			else $meta_title = $product->getName();
			
			$meta_keyword = '';
			if (!empty($row->meta_keyword)) $meta_keyword = $this->compose($product, $row->meta_keyword, $row->attribute_code_list_for_meta_keyword);
			
			$meta_description = '';
			if (!empty($row->meta_description)) $meta_description = $this->compose($product, $row->meta_description, $row->attribute_code_list_for_meta_description);

			$short_description = '';
			if (!empty($row->short_description)) $short_description = $this->compose($product, $row->short_description, $row->attribute_code_list_for_short_description);
			
			if (empty($row->meta_description)) $meta_description = $short_description;
			
			$description = '';
			if (!empty($row->description)) $this->compose($product, $row->description, $row->attribute_code_list_for_description);
			
			$product
				->setMetaTitle($meta_title)
				->setMetaKeyword($meta_keyword)
				->setMetaDescription($meta_description)
				->setShortDescription($short_description)
				->setDescription($description)
				;
		}
		
		public function setStoresProductInfo($row, $product)
		{
			$store = Mage::app()->getStore();
			$this->translateInfo($row, $product, $store);
			$product->save();
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
			//echo '<hr />';
			foreach (Mage::app()->getStores() as $store)
			{
				$currency_code = $store->getCurrentCurrencyCode();
				
				$price = Mage::helper('directory')->currencyConvert($default_price, $admin_store_currency_code, $currency_code);
				if (Mage::getStoreConfig('catalog/lioce_catalogsync/round_price_excess_fifty_cent')) $price = round(ceil(round($price, 2) / 0.5) * 0.5, 2); //arrotonda a 50 cent per eccesso
				
				$product
					->setStoreId($store->getId())
					->setPrice($price)
					;
				
				if (!empty($default_special_price))
				{
					$special_price = Mage::helper('directory')->currencyConvert($default_special_price, $admin_store_currency_code, $currency_code);
					if (Mage::getStoreConfig('catalog/lioce_catalogsync/round_price_excess_fifty_cent')) $special_price = round(ceil(round($special_price, 2) / 0.5) * 0.5, 2); //arrotonda a 50 cent per eccesso
					$product->setSpecialPrice($special_price);
				}
				
				if (!empty($default_cost))
				{
					$cost = Mage::helper('directory')->currencyConvert($default_cost, $admin_store_currency_code, $currency_code);
					$product->setCost($cost);
				}
				
				/*
				echo '<hr />'.$store->getCode().'<br>';
				echo $store->getCode().'<br>';
				echo 'price: '.$default_price.' '.$price.' '.$currency_code.'<br>';
				echo 'special_price: '.$default_special_price.' '.$special_price.' '.$currency_code.'<br>';
				echo 'cost: '.$default_cost.' '.$cost.' '.$currency_code.'<br>';
				echo $product->getSpecialPrice().' '.$product->getFinalPrice().'<br>';
				echo '<hr />';
				continue;
				*/
				
				
				Mage::app()->setCurrentStore($store->getId());
				$this->translateInfo($row, $product, $store);
				Mage::app()->setCurrentStore($current_store_id);
					
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