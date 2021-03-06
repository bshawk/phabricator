<?php

final class PhabricatorWorkerActiveTask extends PhabricatorWorkerTask {

  protected $failureTime;

  private $serverTime;
  private $localTime;

  protected function getConfiguration() {
    $parent = parent::getConfiguration();

    $config = array(
      self::CONFIG_IDS => self::IDS_COUNTER,
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_KEY_SCHEMA => array(
        'dataID' => array(
          'columns' => array('dataID'),
          'unique' => true,
        ),
        'taskClass' => array(
          'columns' => array('taskClass'),
        ),
        'leaseExpires' => array(
          'columns' => array('leaseExpires'),
        ),
        'leaseOwner' => array(
          'columns' => array('leaseOwner(16)'),
        ),
        'key_failuretime' => array(
          'columns' => array('failureTime'),
        ),
        'leaseOwner_2' => array(
          'columns' => array('leaseOwner', 'priority', 'id'),
        ),
      ) + $parent[self::CONFIG_KEY_SCHEMA],
    );

    $config[self::CONFIG_COLUMN_SCHEMA] = array(
      // T6203/NULLABILITY
      // This isn't nullable in the archive table, so at a minimum these
      // should be the same.
      'dataID' => 'uint32?',
    ) + $parent[self::CONFIG_COLUMN_SCHEMA];

    return $config + $parent;
  }

  public function setServerTime($server_time) {
    $this->serverTime = $server_time;
    $this->localTime = time();
    return $this;
  }

  public function setLeaseDuration($lease_duration) {
    $this->checkLease();
    $server_lease_expires = $this->serverTime + $lease_duration;
    $this->setLeaseExpires($server_lease_expires);

    // NOTE: This is primarily to allow unit tests to set negative lease
    // durations so they don't have to wait around for leases to expire. We
    // check that the lease is valid above.
    return $this->forceSaveWithoutLease();
  }

  public function save() {
    $this->checkLease();
    return $this->forceSaveWithoutLease();
  }

  public function forceSaveWithoutLease() {
    $is_new = !$this->getID();
    if ($is_new) {
      $this->failureCount = 0;
    }

    if ($is_new && ($this->getData() !== null)) {
      $data = new PhabricatorWorkerTaskData();
      $data->setData($this->getData());
      $data->save();

      $this->setDataID($data->getID());
    }

    return parent::save();
  }

  protected function checkLease() {
    if ($this->leaseOwner) {
      $current_server_time = $this->serverTime + (time() - $this->localTime);
      if ($current_server_time >= $this->leaseExpires) {
        $id = $this->getID();
        $class = $this->getTaskClass();
        throw new Exception(
          "Trying to update Task {$id} ({$class}) after lease expiration!");
      }
    }
  }

  public function delete() {
    throw new Exception(
      'Active tasks can not be deleted directly. '.
      'Use archiveTask() to move tasks to the archive.');
  }

  public function archiveTask($result, $duration) {
    if ($this->getID() === null) {
      throw new Exception(
        "Attempting to archive a task which hasn't been save()d!");
    }

    $this->checkLease();

    $archive = id(new PhabricatorWorkerArchiveTask())
      ->setID($this->getID())
      ->setTaskClass($this->getTaskClass())
      ->setLeaseOwner($this->getLeaseOwner())
      ->setLeaseExpires($this->getLeaseExpires())
      ->setFailureCount($this->getFailureCount())
      ->setDataID($this->getDataID())
      ->setPriority($this->getPriority())
      ->setObjectPHID($this->getObjectPHID())
      ->setResult($result)
      ->setDuration($duration);

    // NOTE: This deletes the active task (this object)!
    $archive->save();

    return $archive;
  }

  public function executeTask() {
    // We do this outside of the try .. catch because we don't have permission
    // to release the lease otherwise.
    $this->checkLease();

    $did_succeed = false;
    $worker = null;
    try {
      $worker = $this->getWorkerInstance();

      $maximum_failures = $worker->getMaximumRetryCount();
      if ($maximum_failures !== null) {
        if ($this->getFailureCount() > $maximum_failures) {
          $id = $this->getID();
          throw new PhabricatorWorkerPermanentFailureException(
            "Task {$id} has exceeded the maximum number of failures ".
            "({$maximum_failures}).");
        }
      }

      $lease = $worker->getRequiredLeaseTime();
      if ($lease !== null) {
        $this->setLeaseDuration($lease);
      }

      $t_start = microtime(true);
        $worker->executeTask();
      $t_end = microtime(true);
      $duration = (int)(1000000 * ($t_end - $t_start));

      $result = $this->archiveTask(
        PhabricatorWorkerArchiveTask::RESULT_SUCCESS,
        $duration);
      $did_succeed = true;
    } catch (PhabricatorWorkerPermanentFailureException $ex) {
      $result = $this->archiveTask(
        PhabricatorWorkerArchiveTask::RESULT_FAILURE,
        0);
      $result->setExecutionException($ex);
    } catch (PhabricatorWorkerYieldException $ex) {
      $this->setExecutionException($ex);

      $retry = $ex->getDuration();
      $retry = max($retry, 5);

      // NOTE: As a side effect, this saves the object.
      $this->setLeaseDuration($retry);

      $result = $this;
    } catch (Exception $ex) {
      $this->setExecutionException($ex);
      $this->setFailureCount($this->getFailureCount() + 1);
      $this->setFailureTime(time());

      $retry = null;
      if ($worker) {
        $retry = $worker->getWaitBeforeRetry($this);
      }

      $retry = coalesce(
        $retry,
        PhabricatorWorkerLeaseQuery::getDefaultWaitBeforeRetry());

      // NOTE: As a side effect, this saves the object.
      $this->setLeaseDuration($retry);

      $result = $this;
    }

    // NOTE: If this throws, we don't want it to cause the task to fail again,
    // so execute it out here and just let the exception escape.
    if ($did_succeed) {
      foreach ($worker->getQueuedTasks() as $task) {
        list($class, $data) = $task;
        PhabricatorWorker::scheduleTask(
          $class,
          $data,
          array(
            'priority' => $this->getPriority(),
          ));
      }
    }

    return $result;
  }

}
