<?php

final class SprintEdgeTransaction {

	const TYPE_ADD = 'add';
	const TYPE_REMOVE = 'remove';

	private $project;
	private $viewer;

	public function setViewer($viewer) {
		$this->viewer = $viewer;
		return $this;
	}

	public function setProject($project) {
		$this->project = $project;
		return $this;
	}

	public function getStartTasks($xactions, $start) {
		$startTasks = $searchIds = [];
		foreach ($xactions as $xaction) {
			if ($xaction->getDateCreated() < $start) {
				$taskPHID = $xaction->getObjectPHID();
				$old = idx($xaction->getOldValue(), 0);
				$new = idx($xaction->getNewValue(), 0);

				if (in_array($taskPHID, $searchIds)) {
					continue;
				} else {
					$searchIds[] = $taskPHID;
				}

				if ($old == $this->project->getPHID()) {
					continue;
				}
				//add
				if ($new == $this->project->getPHID()) {
					$startTasks[] = $taskPHID;
				}
			}
		}

		if (!empty($startTasks)) {
			$startTasks = id(new ManiphestTaskQuery())
				->setViewer($this->viewer)
				->withPHIDs($startTasks)
				->execute();
		}

		return $startTasks;
	}

	public function getXactionTaskToday($xactionToDates, $date, $tasks) {
		$day = $date->getDate();
		if (isset($xactionToDates[$day])) {
			$actions = $xactionToDates[$day];
			$addTasks = [];
			foreach ($actions as $taskPHID => $action) {
				if ($action == SprintEdgeTransaction::TYPE_REMOVE) {
					foreach ($tasks as $taskId => $task) {
						if ($taskPHID == $task->getPHID()) {
							unset($tasks[$taskId]);
						}
					}
				} elseif ($action == SprintEdgeTransaction::TYPE_ADD) {
					$addTasks[] = $taskPHID;
				}
			}
			if (!empty($addTasks)) {
				$addTasks = id(new ManiphestTaskQuery())
					->setViewer($this->viewer)
					->withPHIDs($addTasks)
					->execute();

				$totalPoints = id(new SprintPoints())
					->setTasks($addTasks)
					->sumTotalTaskPoints();

				$date->setPointsAddedToday($totalPoints);

				$tasks += $addTasks;
			}
		}

		return $tasks;
	}
}