<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\SaplingType;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\generator\object\PaleOakTree;
use function mt_rand;

final class PaleOakSapling extends Sapling{

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo){
		parent::__construct($idInfo, $name, $typeInfo, SaplingType::OAK);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer && $this->growPaleOak($player)){
			$item->pop();
			return true;
		}

		return false;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getFullLightAt($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()) >= 8 && mt_rand(1, 7) === 1){
			if($this->ready){
				$this->growPaleOak(null);
			}else{
				$this->ready = true;
				$world->setBlock($this->position, $this);
			}
		}
	}

	private function growPaleOak(?Player $player) : bool{
		[$anchorX, $anchorZ] = $this->findClusterAnchor();
		if($anchorX === null || $anchorZ === null){
			return false;
		}

		$random = new Random(mt_rand());
		$tree = new PaleOakTree();
		$transaction = $tree->getBlockTransaction($this->position->getWorld(), $anchorX, $this->position->getFloorY(), $anchorZ, $random);
		if($transaction === null){
			return false;
		}

		$ev = new StructureGrowEvent($this, $transaction, $player);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		return $transaction->apply();
	}

	/**
	 * @return array{0: int|null, 1: int|null}
	 */
	private function findClusterAnchor() : array{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$world = $this->position->getWorld();

		foreach([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$xOff, $zOff]){
			$anchorX = $x + $xOff;
			$anchorZ = $z + $zOff;
			if(
				$world->getBlockAt($anchorX, $y, $anchorZ) instanceof self &&
				$world->getBlockAt($anchorX + 1, $y, $anchorZ) instanceof self &&
				$world->getBlockAt($anchorX, $y, $anchorZ + 1) instanceof self &&
				$world->getBlockAt($anchorX + 1, $y, $anchorZ + 1) instanceof self
			){
				return [$anchorX, $anchorZ];
			}
		}

		return [null, null];
	}
}
