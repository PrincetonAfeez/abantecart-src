<?php
/*------------------------------------------------------------------------------
  $Id$

  AbanteCart, Ideal OpenSource Ecommerce Solution
  http://www.AbanteCart.com

  Copyright © 2011-2014 Belavier Commerce LLC

  This source file is subject to Open Software License (OSL 3.0)
  License details is bundled with this package in the file LICENSE.txt.
  It is also available at this URL:
  <http://www.opensource.org/licenses/OSL-3.0>

 UPGRADE NOTE:
   Do not edit or add to this file if you wish to upgrade AbanteCart to newer
   versions in the future. If you wish to customize AbanteCart for your
   needs please refer to http://www.AbanteCart.com for more information.
------------------------------------------------------------------------------*/
if (! defined ( 'DIR_CORE' )) {
	header ( 'Location: static_pages/' );
}
/**
 * @property ADB $db
 */
class ATaskManager {
	protected $registry;
	public $errors = 0; // errors during process
	private $starter;
	private $task_log;

	public function __construct() {
		/*if (! IS_ADMIN) { // forbid for non admin calls
			throw new AException ( AC_ERR_LOAD, 'Error: permission denied to change forms' );
		}*/
		
		$this->registry = Registry::getInstance ();
		$this->starter = IS_ADMIN===true ? 1 : 0; // who is starter

		$this->task_log = new ALog(DIR_LOGS.'task_log.txt');

	}
	
	public function __get($key) {
		return $this->registry->get ( $key );
	}
	
	public function __set($key, $value) {
		$this->registry->set ( $key, $value );
	}

	public function runTasks(){
		$task_list = $this->_getSheduledTasks();
		// run loop tasks
		foreach($task_list as $task){
			//check interval and skip task
			$this->toLog('Tried to run task #'.$task['task_id']);
			if($task['interval']>0 && (time() - dateISO2Int($task['last_time_run']) >= $task['interval'] || is_null($task['last_time_run']))  ){
				$this->toLog('task #'.$task['task_id'].' skipped.');
				continue;
			}
			$task_settings = unserialize($task['settings']);
			$this->_run_steps($task['task_id'], $task_settings);
			$this->toLog('task #'.$task['task_id'].' finished.');
		}
	}


	public function runTask($task_id){
		$this->toLog('Tried to run task #'.$task_id.'.');
		$task_id = (int)$task_id;
		$task = $this->_getSheduledTasks($task_id);

		//check interval and skip task
		if($task['interval']>0
				&& (time() - dateISO2Int($task['last_time_run']) >= $task['interval'] || is_null($task['last_time_run']))  ){
			$this->toLog('task #'.$task_id.' skipped.');
			return false;
		}
		$task_settings = unserialize($task['settings']);
		$this->_run_steps($task['task_id'], $task_settings);
		$this->toLog('task #'.$task_id.' finished.');
		return true;
	}


	private function _getSheduledTasks($task_id = 0){
		$task_id = (int)$task_id;
		//get list only sheduled tasks
		$sql = "SELECT *
				FROM ".$this->db->table('tasks')." t
				WHERE t.status = 1 AND t.starter IN ('".$this->starter."','2')
				".($task_id ? " AND t.task_id = ".$task_id : '');
		$result = $this->db->query($sql);
		return $task_id ? $result->row : $result->rows;
	}

	private function _run_steps($task_id, $task_settings ){
		$task_id = (int)$task_id;
		if(!$task_id){ return false; }

		$this->_update_task_state($task_id, array('status'=>2));//change status of task to active while it run
		$sql = "SELECT *
				FROM ".$this->db->table('task_steps')."
				WHERE task_id = ".$task_id." AND status = 1
				ORDER BY sort_order";
		$result = $this->db->query($sql);

		$task_result = 0;
		$steps_count = $result->num_rows;
		$k=0;
		foreach($result->rows as $step){
			$this->toLog('Tried to run step #'.$step['step_id'].' of task #'.$task_id);
			//change status to active
			$this->_update_step_state( $step['step_id'],
										array( 'result' => 1, // mark last result as "failed"
											   'last_time_run' => date('Y-m-d H:i:s'),
												'status' => 2) ); //change status of step to active while it run

			try{
				$dd = new ADispatcher($step['controller'], $args);
				$result = $dd->dispatchGetOutput($step['controller']);
				$this->_update_step_state( $step['step_id'],
											array('result' => $result,
												  'last_time_run' => date('Y-m-d H:i:s'),
												  'status'=>1) );

			}catch(AException $e){
				$this->log->write('Sheduled step #'.$step['step_id'].' of task #'.$task_id.' failed during process');
				$this->_update_step_state( $step['step_id'],
											array( 'result' => 1, // mark last result as "failed"
										  		   'last_time_run' => date('Y-m-d H:i:s'),
													'status'=>1) );
				$this->toLog('Step #'.$step['step_id'].' of task #'.$task_id.' failed.');

				if($task_settings['interrupt_on_step_fail']===true){
					$this->_update_task_state($task_id, array( 'result' => 1, // mark last result of task as "failed"
															   'last_time_run' => date('Y-m-d H:i:s'),
															   'status'=>1)//change status of task to sheduled for future run
					);
					return false;
				}

				$task_result = 1;
			}
			$this->toLog('Step #'.$step['step_id'].' of task #'.$task_id.' finished.');

			$this->_update_task_state($task_id, array( 'progress' => ceil($k*100/$steps_count)));

		}

		$this->_update_task_state($task_id, array( 'result' => $task_result,
												   'last_time_run' => date('Y-m-d H:i:s'),
													'status' => 1)//change status of task to sheduled for future run
		);
		return true;
	}

	private function _update_task_state($task_id, $state = array()){
		$task_id = (int)$task_id;
		if(!$task_id){ return false; }

		$upd_flds = array('last_result',
						  'last_time_run',
						  'status',
						  'progress');
		$data = array();
		foreach($upd_flds as $fld_name){
			if(has_value($state[$fld_name])){
				$data[$fld_name] = $state[$fld_name];
			}
		}
		return $this->updateTask($task_id,$data);
	}

	private function _update_step_state($step_id, $state = array()){
		$upd_flds = array('task_id',
						  'last_result',
						  'last_time_run',
						  'status');
		$data = array();
		foreach($upd_flds as $fld_name){
			if(has_value($state[$fld_name])){
				$data[$fld_name] = $state[$fld_name];
			}
		}
		return $this->updateStep($step_id,$data);
	}


	public function toLog($message){
		$this->task_log->write($message);
	}

	public function addTask($data = array()){
		$sql = "INSERT INTO ".$this->db->table('tasks')."
				(`name`,`starter`,`status`,`start_time`,`last_time_run`,`progress`,`last_result`,`run_interval`,`max_execution_time`,`date_created`)
				VALUES ('".$this->db->escape($data['name'])."',
						'".(int)$data['starter']."',
						'".(int)$data['status']."',
						'".$this->db->escape($data['start_time'])."',
						'".$this->db->escape($data['last_time_run'])."',
						'".(int)$data['progress']."',
						'".(int)$data['last_result']."',
						'".(int)$data['run_interval']."',
						'".(int)$data['max_execution_time']."',
						NOW())";
		$this->db->query($sql);
		$task_id =  $this->db->getLastId();
		if(has_value($data['created_by']) || has_value($data['settings'])){
			$this->updateTaskDetails($task_id, $data);
		}
		return $task_id;
	}

	public function updateTask($task_id, $data = array()){
		$task_id = (int)$task_id;
		if(!$task_id){ return false; }

		$upd_flds = array(
							'name' => 'string',
							'starter' => 'int',
							'status' => 'int',
							'start_time' => 'timestamp',
							'last_time_run' => 'timestamp',
							'progress' => 'int',
							'last_result' => 'int',
							'run_interval' => 'int',
							'max_execution_time' => 'int',
						  	'date_created' => 'timestamp'
						);
		$update = array();
		foreach($upd_flds as $fld_name => $fld_type){
			if(has_value($data[$fld_name])){
				switch($fld_type){
					case 'int':
						$value = (int)$data[$fld_name];
						break;
					case 'string':
					case 'timestamp':
						$value = $this->db->escape( $data[$fld_name] );
						break;
					default:
						$value = $this->db->escape( $data[$fld_name] );
				}
				$update[] = $fld_name." = ".$value;
			}
		}
		if(!$update){ //if nothing to update
			return false;
		}

		$sql = "UPDATE ".$this->db->table( 'tasks' )."
				SET ".implode(', ', $update)."
				WHERE task_id = ".(int)$task_id;
		$this->db->query($sql);

		if(has_value($data['created_by']) || has_value($data['settings'])){
			$this->updateTaskDetails($task_id, $data);
		}
		return true;
	}


	/**
	 * function insert or update task details
	 * @param $task_id
	 * @param array $data
	 * @return bool
	 */
	public function updateTaskDetails($task_id, $data = array()){
		$task_id = (int)$task_id;
		if(!$task_id){ return false;}

		$sql = "SELECT * FROM ".$this->db->table('task_details')." WHERE task_id = ".$task_id;
		$result = $this->db->query($sql);
		if($result->num_rows){
			foreach($result->row as $k=>$ov){
				if(!has_value($data[$k])){
					$data[$k] = $ov;
				}
			}
			$sql = "UPDATE ".$this->db->table( 'task_details' )."
					SET created_by = '".$this->db->escape($data['created_by'])."',
						settings = '".$this->db->escape($data['settings'])."'
					WHERE task_id = ".$task_id;
		}else{
			$sql = "INSERT INTO ".$this->db->table( 'task_details' )."
					(task_id, created_by, settings, date_created)
					 VALUES (   '".$task_id."',
					 			'".$this->db->escape($data['created_by'])."',
					 			'".$this->db->escape($data['settings'])."',
					 			NOW())";
		}
		$this->db->query($sql);
	}

	public function addStep($data = array()){
		$sql = "INSERT INTO ".$this->db->table('task_steps')."
				(`task_id`,`sort_order`,`status`,`last_time_run`,`last_result`,`max_execution_time`,`controller`, `settings`,`date_created`)
				VALUES (
						'".(int)$data['task_id']."',
						'".(int)$data['sort_order']."',
						'".(int)$data['status']."',
						'".$this->db->escape($data['last_time_run'])."',
						'".(int)$data['last_result']."',
						'".(int)$data['max_execution_time']."',
						'".$this->db->escape($data['controller'])."',
						'".$this->db->escape($data['settings'])."',
						NOW())";
		$this->db->query($sql);
		return $this->db->getLastId();
	}

	public function updateStep($step_id, $data = array()){
		$step_id = (int)$step_id;
		if(!$step_id){ return false; }

		$upd_flds = array(
							'task_id' => 'int',
							'starter' => 'int',
							'status' => 'int',
							'sort_order' => 'int',
							'last_time_run' => 'timestamp',
							'last_result' => 'int',
							'max_execution_time' => 'int',
							'controller' => 'string',
							'settings' => 'string',
							'date_created' => 'timestamp'
						);
		$update = array();
		foreach($upd_flds as $fld_name => $fld_type){
			if(has_value($data[$fld_name])){
				switch($fld_type){
					case 'int':
						$value = (int)$data[$fld_name];
						break;
					case 'string':
					case 'timestamp':
						$value = $this->db->escape( $data[$fld_name] );
						break;
					default:
						$value = $this->db->escape( $data[$fld_name] );
				}
				$update[] = $fld_name." = ".$value;
			}
		}
		if(!$update){ //if nothing to update
			return false;
		}

		$sql = "UPDATE ".$this->db->table( 'task_steps' )."
				SET ".implode(', ', $update)."
				WHERE step_id = ".(int)$step_id;
		$this->db->query($sql);
		return true;
	}

	public function deleteTask($task_id){
		$sql[] = "DELETE FROM ".$this->db->table('tasks')." WHERE task_id = '".(int)$task_id."'";
		$sql[] = "DELETE FROM ".$this->db->table('task_steps')." WHERE task_id = '".(int)$task_id."'";
		$sql[] = "DELETE FROM ".$this->db->table('task_details')." WHERE task_id = '".(int)$task_id."'";
		foreach($sql as $q){
			$this->db->query($q);
		}
	}

	public function deleteStep($step_id){
		$sql = "DELETE FROM ".$this->db->table('task_steps')." WHERE step_id = '".(int)$step_id."'";
		$this->db->query($sql);
	}


	public function getTask($task_id = 0){
		$task_id = (int)$task_id;
		//get list only sheduled tasks
		$sql = "SELECT *
				FROM ".$this->db->table('tasks')." t
				LEFT JOIN ".$this->db->table('task_details')." td ON td.task_id = t.task_id
				WHERE t.task_id = ".$task_id;
		$result = $this->db->query($sql);
		return $result->row;
	}



}