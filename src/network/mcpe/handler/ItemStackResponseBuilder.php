<?php

/*
 *
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
 * @author Ayrz
 * @team BeeltyMine
 * 
 * 
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\inventory\Inventory;
use pocketmine\item\Durable;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseContainerInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseSlotInfo;
final class ItemStackResponseBuilder{

	/**
	 * @var array<string, array{containerName: FullContainerName, slots: array<int, int>}>
	 * @phpstan-var array<string, array{containerName: FullContainerName, slots: array<int, int>}>
	 */
	private array $changedSlots = [];

	public function __construct(
		private int $requestId,
		private InventoryManager $inventoryManager
	){}

	public function addSlot(FullContainerName $containerName, int $slotId) : void{
		$key = $containerName->getContainerId() . ":" . ($containerName->getDynamicId() ?? "");
		$this->changedSlots[$key] ??= [
			"containerName" => $containerName,
			"slots" => []
		];
		$this->changedSlots[$key]["slots"][$slotId] = $slotId;
	}

	/**
	 * @phpstan-return array{Inventory, int}
	 */
	private function getInventoryAndSlot(FullContainerName $containerName, int $slotId) : ?array{
		if($containerName->getContainerId() === ContainerUIIds::DYNAMIC){
			$dynamicId = $containerName->getDynamicId();
			if($dynamicId === null){
				return null;
			}
			$windowAndSlot = $this->inventoryManager->locateDynamicInventoryAndSlot($dynamicId, $slotId);
		}else{
			[$windowId, $slotId] = ItemStackContainerIdTranslator::translate($containerName->getContainerId(), $this->inventoryManager->getCurrentWindowId(), $slotId);
			$windowAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slotId);
		}
		if($windowAndSlot === null){
			return null;
		}
		[$inventory, $slot] = $windowAndSlot;
		if(!$inventory->slotExists($slot)){
			return null;
		}

		return [$inventory, $slot];
	}

	public function build() : ItemStackResponse{
		$responseInfosByContainer = [];
		$containerNames = [];
		foreach($this->changedSlots as $key => $entry){
			$containerName = $entry["containerName"];
			$containerInterfaceId = $containerName->getContainerId();
			if($containerInterfaceId === ContainerUIIds::CREATED_OUTPUT){
				continue;
			}
			$containerNames[$key] = $containerName;
			foreach($entry["slots"] as $slotId){
				$inventoryAndSlot = $this->getInventoryAndSlot($containerName, $slotId);
				if($inventoryAndSlot === null){
					//a plugin may have closed the inventory during an event, or the slot may have been invalid
					continue;
				}
				[$inventory, $slot] = $inventoryAndSlot;

				$itemStackInfo = $this->inventoryManager->getOrCreateItemStackInfo($inventory, $slot);
				if($itemStackInfo === null){
					continue;
				}
				$item = $inventory->getItem($slot);

				$responseInfosByContainer[$key][] = new ItemStackResponseSlotInfo(
					$slotId,
					$slotId,
					$item->getCount(),
					$itemStackInfo->getStackId(),
					$item->getCustomName(),
					$item->getCustomName(),
					$item instanceof Durable ? $item->getDamage() : 0,
				);
			}
		}

		$responseContainerInfos = [];
		foreach($responseInfosByContainer as $key => $responseInfos){
			$responseContainerInfos[] = new ItemStackResponseContainerInfo($containerNames[$key], $responseInfos);
		}

		return new ItemStackResponse(ItemStackResponse::RESULT_OK, $this->requestId, $responseContainerInfos);
	}
}
