<?php


final class BoardDataProvider {

  private $start;
  private $end;
  private $project;
  private $viewer;
  private $request;
  private $tasks;
  private $query;
  private $stats;
  private $timezone;
  private $timeseries;
  private $chartdata;
  private $coldata;

  public function setStart($start) {
    $this->start = $start;
    return $this;
  }

  public function setEnd($end) {
    $this->end = $end;
    return $this;
  }

  public function setProject($project) {
    $this->project = $project;
    return $this;
  }

  public function getProject() {
    return $this->project;
  }

  public function getColumnData() {
    return $this->coldata;
  }

  public function getChartData() {
    return $this->chartdata;
  }

  public function setViewer($viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRequest($request) {
    $this->request = $request;
    return $this;
  }

  public function setTimezone($timezone) {
    $this->timezone = $timezone;
    return $this;
  }

  public function setTimeSeries($timeseries) {
    $this->timeseries = $timeseries;
    return $this;
  }

  public function getTimeSeries() {
    return $this->timeseries;
  }

  public function setTasks($tasks) {
    $this->tasks = $tasks;
    return $this;
  }

  public function getTasks() {
    return $this->tasks;
  }

  public function setStats($stats) {
    $this->stats = $stats;
    return $this;
  }

  public function setQuery($query) {
    $this->query = $query;
    return $this;
  }

  public function execute() {
    $this->buildBoardDataSet();
    $this->buildChartfromBoardData();
    return $this;
  }

  private function buildBoardDataSet() {
    $board_columns = array();
    $columns = $this->query->getProjectColumns();
    $positions = $this->query->getProjectColumnPositionforTask($this->tasks,
        $columns);
    $task_map = array();

    foreach ($this->tasks as $task) {
      $task_phid = $task->getPHID();
      if (empty($positions[$task_phid])) {
        continue;
      }
      $position = $positions[$task_phid];
      $task_map[$position->getColumnPHID()][] = $task_phid;
    }

    foreach ($columns as $column) {
      $board_column = $this->buildColumnTasks($column, $task_map);
      $board_columns[$column->getPHID()] = $board_column;

    }
    $this->coldata = $this->buildBoardColumnData($board_columns);
    return $this;
  }

  private function buildBoardColumnData($board_columns) {
    $coldata = array();
    foreach ($board_columns as $column_phid => $tasks) {
      $colname = $this->getColumnName($column_phid);
      $task_count = count($tasks);
      $task_points_total = $this->getTaskPointsSum($tasks);
      $coldata[] = array(
          $colname,
          $task_count,
          $task_points_total,
      );
    }
    return $coldata;
  }

  private function buildColumnTasks($column, $task_map) {
    $task_phids = idx($task_map, $column->getPHID(), array());
    $column_tasks = array_select_keys($this->tasks, $task_phids);
    return $column_tasks;
  }

  private function getColumnName($column_phid) {
    $name = null;
    $column = $this->query->getColumnforPHID($column_phid);
    foreach ($column as $obj) {
      $name = $obj->getDisplayName();
    }
    return $name;
  }

  private function getTaskPointsSum($tasks) {
    $points_sum = null;
 //   $taskpoints = mpull($this->taskpoints, null, 'getObjectPHID');
 //   $column_points = array_intersect_key($taskpoints, $tasks);
 //   if (!empty($column_points)) {
      foreach ($tasks as $task) {
          $points = $task->getPoints();
          $points_sum += $points;
      }
      if (!isset($points_sum)) {
        $points_sum = '0';
      }
//    }
    return $points_sum;
  }

  private function getProjectColumnXactions() {
    $xactions = array();
    $scope_phid = $this->project->getPHID();
    $task_phids = mpull($this->tasks, 'getPHID');
    $query = new ManiphestTransactionQuery();
    $query->withTransactionTypes(array(PhabricatorTransactions::TYPE_COLUMNS));
    $query->withObjectPHIDs($task_phids);
    $query->setViewer($this->viewer);
    $col_xactions = $query->execute();
    foreach ($col_xactions as $xaction) {
      $xaction_date = $xaction->getDateCreated();
      if ($xaction_date >= $this->start && $xaction_date <= $this->end) {
        $newval = $xaction->getNewValue();
          $newArr = call_user_func_array('array_merge', $newval);
              if ($newArr['boardPHID'] == $scope_phid) {
                  $xactions[] = $xaction;
              }
      }
    }
    return $xactions;
  }

	private function buildChartfromBoardData() {
		$date_array = $this->stats->buildDateArray($this->start, $this->end,
			$this->timezone);

		$xactions = $this->getProjectColumnXactions();
		$xaction_map = mpull($xactions, null, 'getPHID');

		$sprint_xaction = id(new SprintColumnTransaction())
			->setViewer($this->viewer)
			->setTasks($this->tasks)
			->setQuery($this->query)
			->setProject($this->project)
			->setEvents($xactions);
		$dates = $sprint_xaction->parseEvents($date_array, $xaction_map);

		$timeStamp = (new DateTime())->getTimestamp();
		if ($timeStamp > $this->start) {

			$xactions = $this->query->getXactionProjects();

			$sprintEdgeTransaction = new SprintEdgeTransaction();
			$sprintEdgeTransaction
				->setProject($this->project)
				->setViewer($this->viewer);

			$tasks = $sprintEdgeTransaction->getStartTasks($xactions, $this->start);

			$xactionToDates = $this->prepareXactions($xactions);

			$totalPoints = id(new SprintPoints())
				->setTasks($tasks)
				->sumTotalTaskPoints();
			$i = $totalClose = 0;
			foreach ($dates as $day => $date) {

				if ($i) {
					$tasks = $sprintEdgeTransaction->getXactionTaskToday($xactionToDates, $date, $tasks);
					$totalPoints = id(new SprintPoints())
						->setTasks($tasks)
						->sumTotalTaskPoints();
				}

				$date->setPointsTotal($totalPoints);

				$points_closed_today = $date->getPointsClosedToday();
				$points_reopened_today = $date->getPointsReopenedToday();
				$points_today = $points_reopened_today - $points_closed_today;

				$totalClose += $points_today;
				$points_remaining = $totalClose + $totalPoints;

				$date->setPointsRemaining($points_remaining);

				$i++;
			}

			$dates = $this->stats->computeIdealPoints($dates);
			$data = $this->stats->buildDataSet($dates);

		} else {
			$this->stats->setTasks($this->tasks);
			$sprint_data = $this->stats->setSprintData($dates);
			$data = $this->stats->buildDataSet($sprint_data);
		}

		$this->chartdata = $this->stats->transposeArray($data);
		return $this;
	}

	private function prepareXactions($xactions) {
		$result = [];
		foreach ($xactions as $xaction) {
			$taskPHID = $xaction->getObjectPHID();
			$old = idx($xaction->getOldValue(), 0);
			$new = idx($xaction->getNewValue(), 0);
			$dateCreated = phabricator_format_local_time($xaction->getDateCreated(),
				$this->viewer, 'D M j');

			if ($old == $this->project->getPHID()) {
				$result[$dateCreated][$taskPHID][] = SprintEdgeTransaction::TYPE_REMOVE;
			} elseif ($new == $this->project->getPHID()) {
				$result[$dateCreated][$taskPHID][] = SprintEdgeTransaction::TYPE_ADD;
			}
		}

		foreach ($result as $date => $tasks) {
			foreach ($tasks as $task => $actions) {
				if (count($actions)%2 == 0) {
					unset($result[$date][$task]);
					if (empty($result[$date])) {
						unset($result[$date]);
					}
				} else {
					$result[$date][$task] = $actions[0];
				}
			}
		}

		return $result;
	}
}
