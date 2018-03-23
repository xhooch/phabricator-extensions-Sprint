<?php

final class ManiphestTransactionCustomQuery
	extends PhabricatorApplicationTransactionQuery {

	private $oldValue;
	private $newValue;
	private $value;

	public function getTemplateApplicationTransaction() {
		return new ManiphestTransaction();
	}


	public function withOldValue($value) {
		$this->oldValue = $value;
		return $this;
	}

	public function withNewValue($value) {
		$this->newValue = $value;
		return $this;
	}

	public function withValue($value) {
		$this->value = $value;
		return $this;
	}

	protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
		$where = parent::buildWhereClauseParts($conn);

		if ($this->oldValue !== null) {
			$where[] = qsprintf(
				$conn,
				'x.oldValue = %s',
				$this->oldValue);
		}

		if ($this->newValue !== null) {
			$where[] = qsprintf(
				$conn,
				'x.newValue = %s',
				$this->newValue);
		}

		if ($this->value !== null) {
			$where[] = qsprintf(
				$conn,
				'(x.oldValue = %s OR x.newValue = %s)',
				$this->value,
				$this->value);
		}

		return $where;
	}

}
