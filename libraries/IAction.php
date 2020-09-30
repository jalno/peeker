<?php
namespace packages\peeker;

interface IAction extends \Serializable {
	public function getScanner(): ?IScanner;
	public function hasConflict(IAction $other): bool;
	public function isValid(): bool;
	public function do(): void;
	public function getReason(): ?string;
}
