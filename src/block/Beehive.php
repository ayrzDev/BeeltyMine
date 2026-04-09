<?php

/*
 *     ____            ____        __  ____
 *    / __ )___  ___  / / /___  __/  |/  (_)___  ___
 *   / __  / _ \/ _ \/ / __/ / / / /|_/ / / __ \/ _ \
 *  / /_/ /  __/  __/ / /_/ /_/ / /  / / / / / /  __/
 * /_____/\___/\___/_/\__/\__, /_/  /_/_/_/ /_/\___/
 *                       /____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Ayrz - bonbionTR
 * @team BeeltyMine
 * 
 * 
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Beehive as TileBeehive;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Bee;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\BeehiveShearSound;
use pocketmine\world\World;

final class Beehive extends Opaque implements HorizontalFacing{
	use HorizontalFacingTrait;

	private int $honeyLevel = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(0, 5, $this->honeyLevel);
	}

	public function getHoneyLevel() : int{
		return $this->honeyLevel;
	}

	/** @return $this */
	public function setHoneyLevel(int $honeyLevel) : self{
		if($honeyLevel < 0 || $honeyLevel > 5){
			throw new \InvalidArgumentException("Honey level must be in range 0 ... 5");
		}
		$this->honeyLevel = $honeyLevel;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		$nbt = $item->getCustomBlockData();
		if($nbt !== null){
			$this->honeyLevel = $nbt->getInt("HoneyLevel", 0);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if($tile instanceof TileBeehive && $tile->onUpdate()){
			$world->scheduleDelayedBlockUpdate($this->position, 20);
		}
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getSilkTouchDrops(Item $item) : array{
		$drop = $this->asItem();
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		$tileNbt = CompoundTag::create();
		$tileNbt->setInt("HoneyLevel", $this->honeyLevel);
		if($tile instanceof TileBeehive){
			$list = new ListTag();
			foreach($tile->getStoredBees() as $bee){
				$list->push(clone $bee);
			}
			$tileNbt->setTag("Bees", $list);
		}
		$drop->setCustomBlockData($tileNbt);
		return [$drop];
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if($tile instanceof TileBeehive){
			if($item->hasEnchantment(VanillaEnchantments::SILK_TOUCH())){
				$tile->setSilkTouched(true);
			}elseif($player !== null){
				$tile->setAngryTarget($player);
			}
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->honeyLevel < 5){
			return false;
		}
		$world = $this->position->getWorld();
		if($item instanceof Shears){
			$world->setBlock($this->position, $this->setHoneyLevel(0));
			$world->dropItem($this->position->add(0.5, 0.0, 0.5), VanillaItems::HONEYCOMB()->setCount(3));
			$world->addSound($this->position, new BeehiveShearSound());
			$item->applyDamage(1);
		}elseif($item->getTypeId() === ItemTypeIds::GLASS_BOTTLE){
			$world->setBlock($this->position, $this->setHoneyLevel(0));
			$item->pop();
			$returnedItems[] = VanillaItems::HONEY_BOTTLE();
		}else{
			return false;
		}
		if($player !== null && !$this->hasCampfireBelow($world)){
			$tile = $world->getTile($this->position);
			if($tile instanceof TileBeehive){
				$tile->releaseAngryBees($player);
			}
			Bee::alertNearbyBees($player, $this->position, $world);
		}
		return true;
	}

	private function hasCampfireBelow(World $world) : bool{
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		for($y = $this->position->getFloorY() - 1, $minY = $y - 4; $y >= $minY; --$y){
			if(!$world->isInWorld($x, $y, $z)){
				break;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if($block instanceof Campfire && $block->isLit()){
				return true;
			}
		}
		return false;
	}

	public function getFuelTime() : int{
		return 300;
	}
}
