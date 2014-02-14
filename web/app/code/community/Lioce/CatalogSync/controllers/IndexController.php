<?php
/*

*/
	class Lioce_CatalogSync_IndexController extends Mage_Core_Controller_Front_Action
	{
		public function indexAction()
		{
			if (Mage::getStoreConfig('catalog/lioce_catalogsync/lock_cron'))
			{
				//bloccato da amministrazione
				echo 'cron is locked by administration panel';
			}
			else 
			{
				$this->check();
			}
		}
		
		public function manualAction()
		{
			/*
			$data = array(
				array(
					'attribute_code'=>'text',
					'input'=>'text',
					'value'=>'Hello'
				),
				array(
					'attribute_code'=>'size',
					'input'=>'select',
					'type'=>'admin_store_label',
					'value'=>4
				)
			);					
					
			echo Zend_Json::encode($data);
			*/
			$this->check();
		}
		
		public function check()
		{
			$resource = Mage::getSingleton('core/resource');
			$connection = $resource->getConnection('core_write');
			$table_data = new Zend_Db_Table(array('name' => $resource->getTableName('lioce_catalogsync_data'), 'db' => $connection));
			$sync_row = $table_data->find(1)->current();
			if (!$sync_row)
			{
				$table_data->insert(
					array(
						'id' => 1, 
						'description' => 'sync', 
						'start' => new Zend_Db_Expr('NOW()'), 
						'end' => new Zend_Db_Expr('NOW()')
					)
				);
				$sync_row = $table_data->find(1)->current();
			}
			if ($sync_row->start <= $sync_row->end)
			{
				//il cron precedente e' terminato, puo' ripartire
				$this->sync($sync_row);
			}
			else
			{
				$start  = strtotime($sync_row->start);
				$end = strtotime($sync_row->end);
				$difference_in_seconds = $end - $start;
				$waiting_time_in_seconds = 20 * 60;
			
				if ($difference_in_seconds >= $waiting_time_in_seconds)
				{
					//probabilmente il cron precedente non ha terminato, ma abbiamo superato il tempo di attesa, puo' ripartire
					$this->sync($sync_row);
				}
				else
				{
					//il cron precedente potrebbe essere ancora in esecuzione, non abbiamo superato il tempo di attesa, non deve ripartire
					echo 'there is already an active cron';
				}
			}
			$connection->closeConnection();
		}
		
		public function sync($sync_row)
		{
			$sync_row->start = new Zend_Db_Expr('NOW()');
			$sync_row->save();
			
			$this->start();
			
			$sync_row->end = new Zend_Db_Expr('NOW()');
			$sync_row->save();
		}
	
		public function start()
		{
			$start = time();
			Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
			$limit = 1000;
			
			$resource = Mage::getSingleton('core/resource');
			$connection = $resource->getConnection('core_write');
			$table_products = new Zend_Db_Table(array('name' => $resource->getTableName('lioce_catalogsync_products_simple'), 'db' => $connection));
			$select = $table_products->select()
				->where('sku != ""')
				->where('attribute_set_id > 0')
				->where('name != ""')
				->where('short_description != ""')
				->where('description != ""')
				->where('updated_at > imported_at')
				->where('error_message is null')
				->where('
					status = 1 or 
					(
						status = 2 and 
						magento_product_id != 0
					)
				')
				->order(array('updated_at asc', 'id asc'))
				->limit($limit)
			;
				
			if (Mage::getStoreConfig('catalog/lioce_catalogsync/allow_price_zero')) $select	->where('price >= 0');
			else $select->where('price > 0');
			
			if (!Mage::getStoreConfig('catalog/lioce_catalogsync/allow_products_without_image'))
			{
				$select->where('
					image_url != "" and 
					image_url like "http%" and 
					(
						image_url like "%.png" or 
						image_url like "%.jpg" or 
						image_url like "%.jpeg" or 
						image_url like "%.bmp" or 
						image_url like "%.gif"
					)'
				);
			}
			else {}
			
			//echo $select; return;
			
			$i = 0;
			$rowset = $table_products->fetchAll($select);
			foreach ($rowset as $row)
			{
				//set reindex manual
				$indexing_processes = Mage::getSingleton('index/indexer')->getProcessesCollection(); 
				
				foreach ($indexing_processes as $indexing_process)
				{
					$indexing_process->setMode(Mage_Index_Model_Process::MODE_MANUAL)->save();
				}
			
				$i++;
				try
				{
					if ($row->magento_product_id > 0)
					{
						$product = Mage::getModel('catalog/product')->load($row->magento_product_id);
						Mage::helper('catalogsync')->update($row, $product);
						echo 'update magento_product_id: '.$row->magento_product_id.'<br>';
						
						$row->imported_at = new Zend_Db_Expr('NOW()');
						$row->save();
					}
					else
					{
						$product_id = Mage::helper('catalogsync')->add($row);
						if ($product_id > 0)
						{
							echo 'save magento_product_id: '.$product_id.'<br>';
							$row->imported_at = new Zend_Db_Expr('NOW()');
							$row->magento_product_id = $product_id;
							$row->save();
						}
					}
				}
				catch(Exception $e)
				{
					$row->error_message = $e->getMessage();
					$row->save();
					echo $e->getMessage().'<br>';
				}
				
				$partial = time();
				$spent_time = $partial - $start;
				if ($spent_time > 105) break;
			}
			
			$end = time();
			$m = floor(($end - $start) / 60);
			$s = $end - $start - ($m * 60);
			$time = 'minuti '.$m.' secondi '.$s;
			echo '<br><br>'.$time;
			
			
			if (count($rowset) < $limit)
			{
				$indexing_processes = Mage::getSingleton('index/indexer')->getProcessesCollection(); 
				foreach ($indexing_processes as $indexing_process)
				{
					$indexing_process->setMode(Mage_Index_Model_Process::MODE_REAL_TIME)->save();
				}
			
				$email = Mage::getStoreConfig('catalog/lioce_catalogsync/email_notification');
				if ($email) mail($email, "CatalogSync ended", "");
			}
		}
	}
?>