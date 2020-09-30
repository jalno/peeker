<?php
namespace packages\peeker;

interface IActionInteractive extends IAction {
	public function setInterface(IInterface $interface): void;
	public function getInterface(): ?IInterface;
	public function hasQuestions(): bool;
	public function askQuestions(): void;

}
