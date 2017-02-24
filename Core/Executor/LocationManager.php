<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use eZ\Publish\API\Repository\Values\Content\Location;
use Kaliop\eZMigrationBundle\API\Collection\ContentCollection;
use Kaliop\eZMigrationBundle\API\Collection\LocationCollection;
use Kaliop\eZMigrationBundle\Core\Matcher\ContentMatcher;
use Kaliop\eZMigrationBundle\Core\Matcher\LocationMatcher;
use Kaliop\eZMigrationBundle\Core\Helper\SortConverter;

class LocationManager extends RepositoryExecutor
{
    protected $supportedStepTypes = array('location');
    protected $supportedActions = array('create', 'load', 'update', 'delete');

    protected $contentMatcher;
    protected $locationMatcher;
    protected $sortConverter;

    public function __construct(ContentMatcher $contentMatcher, LocationMatcher $locationMatcher, SortConverter $sortConverter)
    {
        $this->contentMatcher = $contentMatcher;
        $this->locationMatcher = $locationMatcher;
        $this->sortConverter = $sortConverter;
    }

    /**
     * Method to handle the create operation of the migration instructions
     */
    protected function create()
    {
        $locationService = $this->repository->getLocationService();

        if (!isset($this->dsl['parent_location']) && !isset($this->dsl['parent_location_id'])) {
            throw new \Exception('Missing parent location id. This is required to create the new location.');
        }

        // support legacy tag: parent_location_id
        if (!isset($this->dsl['parent_location']) && isset($this->dsl['parent_location_id'])) {
            $parentLocationIds = $this->dsl['parent_location_id'];
        } else {
            $parentLocationIds = $this->dsl['parent_location'];
        }

        if (!is_array($parentLocationIds)) {
            $parentLocationIds = array($parentLocationIds);
        }

        // resolve references and remote ids
        foreach ($parentLocationIds as $id => $parentLocationId) {
            $parentLocationId = $this->referenceResolver->resolveReference($parentLocationId);
            $parentLocationIds[$id] = $this->matchLocationByKey($parentLocationId)->id;
        }

        $contentCollection = $this->matchContents('create');

        $locations = null;
        foreach ($contentCollection as $content) {
            $contentInfo = $content->contentInfo;

            foreach ($parentLocationIds as $parentLocationId) {
                $locationCreateStruct = $locationService->newLocationCreateStruct($parentLocationId);

                if (isset($this->dsl['is_hidden'])) {
                    $locationCreateStruct->hidden = $this->dsl['is_hidden'];
                }

                if (isset($this->dsl['priority'])) {
                    $locationCreateStruct->priority = $this->dsl['priority'];
                }

                if (isset($this->dsl['sort_order'])) {
                    $locationCreateStruct->sortOrder = $this->getSortOrder($this->dsl['sort_order']);
                }

                if (isset($this->dsl['sort_field'])) {
                    $locationCreateStruct->sortField = $this->getSortField($this->dsl['sort_field']);
                }

                $locations[] = $locationService->createLocation($contentInfo, $locationCreateStruct);
            }
        }

        $locationCollection = new LocationCollection($locations);

        $this->setReferences($locationCollection);

        return $locationCollection;
    }

    protected function load()
    {
        $locationCollection = $this->matchLocations('load');

        if (count($locationCollection) > 1 && isset($this->dsl['references'])) {
            throw new \Exception("Can not execute Location load because multiple locations match, and a references section is specified in the dsl. References can be set when only 1 location matches");
        }

        $this->setReferences($locationCollection);

        return $locationCollection;
    }

    /**
     * Updates information for a location like priority, sort field and sort order.
     * Updates the visibility of the location when needed.
     * Can move a location and its children to a new parent location or swap two locations.
     *
     * @todo add support for flexible matchers
     */
    protected function update()
    {
        $locationService = $this->repository->getLocationService();

        $locationCollection = $this->matchLocations('update');

        if (count($locationCollection) > 1 && isset($this->dsl['references'])) {
            throw new \Exception("Can not execute Location update because multiple locations match, and a references section is specified in the dsl. References can be set when only 1 location matches");
        }

        if (count($locationCollection) > 1 && isset($this->dsl['swap_with_location'])) {
            throw new \Exception("Can not execute Location update because multiple locations match, and a swap_with_location is specified in the dsl.");
        }

        // support legacy tag: parent_location_id
        if (isset($this->dsl['swap_with_location']) && (isset($this->dsl['parent_location']) || isset($this->dsl['parent_location_id']))) {
            throw new \Exception('Cannot move location to a new parent and swap location with another location at the same time.');
        }

        foreach ($locationCollection as $key => $location) {

            if (isset($this->dsl['priority'])
                || isset($this->dsl['sort_field'])
                || isset($this->dsl['sort_order'])
                || isset($this->dsl['remote_id'])
            ) {
                $locationUpdateStruct = $locationService->newLocationUpdateStruct();

                    if (isset($this->dsl['priority'])) {
                        $locationUpdateStruct->priority = $this->dsl['priority'];
                    }

                    if (isset($this->dsl['sort_field'])) {
                        $locationUpdateStruct->sortField = $this->getSortField($this->dsl['sort_field'], $location->sortField);
                    }

                    if (isset($this->dsl['sort_order'])) {
                        $locationUpdateStruct->sortOrder = $this->getSortOrder($this->dsl['sort_order'], $location->sortOrder);
                    }

                    if (isset($this->dsl['remote_id'])) {
                        $locationUpdateStruct->remoteId = $this->dsl['remote_id'];
                    }

                $location = $locationService->updateLocation($location, $locationUpdateStruct);
            }

            // Check if visibility needs to be updated
            if (isset($this->dsl['is_hidden'])) {
                if ($this->dsl['is_hidden']) {
                    $location = $locationService->hideLocation($location);
                } else {
                    $location = $locationService->unhideLocation($location);
                }
            }

            // Move or swap location
            if (isset($this->dsl['parent_location']) || isset($this->dsl['parent_location_id'])) {
                // Move the location and all its children to a new parent
                $parentLocationId = isset($this->dsl['parent_location']) ? $this->dsl['parent_location'] : $this->dsl['parent_location_id'];
                $parentLocationId = $this->referenceResolver->resolveReference($parentLocationId);

                $newParentLocation = $locationService->loadLocation($parentLocationId);

                $locationService->moveSubtree($location, $newParentLocation);
            } elseif (isset($this->dsl['swap_with_location'])) {
                // Swap locations
                $swapLocationId = $this->dsl['swap_with_location'];
                $swapLocationId = $this->referenceResolver->resolveReference($swapLocationId);

                $locationToSwap = $this->matchLocationByKey($swapLocationId);

                $locationService->swapLocation($location, $locationToSwap);
            }

            $locationCollection[$key] = $location;
        }

        $this->setReferences($locationCollection);

        return $locationCollection;
    }

    /**
     * Delete locations
     *
     * @todo add support for flexible matchers
     */
    protected function delete()
    {
        $locationService = $this->repository->getLocationService();

        $locationCollection = $this->matchLocations('delete');

        foreach ($locationCollection as $location) {
            $locationService->deleteLocation($location);
        }

        return $locationCollection;
    }

    /**
     * @param string $action
     * @return LocationCollection
     * @throws \Exception
     */
    protected function matchLocations($action)
    {
        if (!isset($this->dsl['location_id']) && !isset($this->dsl['match'])) {
            throw new \Exception("The ID or a Match Condition is required to $action a location.");
        }

        // Backwards compat
        if (!isset($this->dsl['match'])) {
            $this->dsl['match'] = array('location_id' => $this->dsl['location_id']);
        }

        $match = $this->dsl['match'];

        // convert the references passed in the match
        foreach ($match as $condition => $values) {
            if (is_array($values)) {
                foreach ($values as $position => $value) {
                    $match[$condition][$position] = $this->referenceResolver->resolveReference($value);
                }
            } else {
                $match[$condition] = $this->referenceResolver->resolveReference($values);
            }
        }

        return $this->locationMatcher->match($match);
    }

    /**
     * @param int|string|array $locationKey
     * @return Location
     */
    public function matchLocationByKey($locationKey)
    {
        return $this->locationMatcher->matchOneByKey($locationKey);
    }

    /**
     * NB: weirdly enough, it returns contents, not locations
     *
     * @param string $action
     * @return ContentCollection
     * @throws \Exception
     */
    protected function matchContents($action)
    {
        if (!isset($this->dsl['object_id']) && !isset($this->dsl['remote_id']) && !isset($this->dsl['match'])) {
            throw new \Exception("The ID or remote ID of an object or a Match Condition is required to $action a new location.");
        }

        // Backwards compat
        if (!isset($this->dsl['match'])) {
            if (isset($this->dsl['object_id'])) {
                $this->dsl['match'] = array('content_id' => $this->dsl['object_id']);
            } elseif (isset($this->dsl['remote_id'])) {
                $this->dsl['match'] = array('content_remote_id' => $this->dsl['remote_id']);
            }
        }

        $match = $this->dsl['match'];

        // convert the references passed in the match
        foreach ($match as $condition => $values) {
            if (is_array($values)) {
                foreach ($values as $position => $value) {
                    $match[$condition][$position] = $this->referenceResolver->resolveReference($value);
                }
            } else {
                $match[$condition] = $this->referenceResolver->resolveReference($values);
            }
        }

        return $this->contentMatcher->matchContent($match);
    }

    /**
     * @param $newValue
     * @param null $currentValue
     * @return int|null
     *
     * * @todo make protected
     */
    public function getSortField($newValue, $currentValue = null)
    {
        $sortField = $currentValue;

        if ($newValue !== null) {
            $sortField = $this->sortConverter->hash2SortField($newValue);
        }

        return $sortField;
    }

    /**
     * Get the sort order based on the current value and the value in the DSL definition.
     *
     * @see \eZ\Publish\API\Repository\Values\Content\Location::SORT_ORDER_*
     *
     * @param int $newValue
     * @param int $currentValue
     * @return int|null
     *
     * @todo make protected
     */
    public function getSortOrder($newValue, $currentValue = null)
    {
        $sortOrder = $currentValue;

        if ($newValue !== null) {
            $sortOrder = $this->sortConverter->hash2SortOrder($newValue);
        }

        return $sortOrder;
    }

    /**
     * Sets references to object attributes
     *
     * The Location Manager currently supports setting references to location id.
     *
     * @throws \InvalidArgumentException When trying to set a reference to an unsupported attribute.
     * @param \eZ\Publish\API\Repository\Values\Content\Location|LocationCollection $location
     * @return boolean
     */
    protected function setReferences($location)
    {
        if (!array_key_exists('references', $this->dsl)) {
            return false;
        }

        if ($location instanceof LocationCollection) {
            if (count($location) > 1) {
                throw new \InvalidArgumentException('Location Manager does not support setting references for creating/updating of multiple locations');
            }
            $location = reset($location);
        }

        foreach ($this->dsl['references'] as $reference) {
            switch ($reference['attribute']) {
                case 'location_id':
                case 'id':
                    $value = $location->id;
                    break;
                case 'remote_id':
                case 'location_remote_id':
                    $value = $location->remoteId;
                    break;
                case 'always_available':
                    $value = $location->contentInfo->alwaysAvailable;
                    break;
                case 'content_id':
                    $value = $location->contentId;
                    break;
                case 'content_type_id':
                    $value = $location->contentInfo->contentTypeId;
                    break;
                case 'content_type_identifier':
                    $contentTypeService = $this->repository->getContentTypeService();
                    $value = $contentTypeService->loadContentType($location->contentInfo->contentTypeId)->identifier;
                    break;
                case 'current_version':
                case 'current_version_no':
                    $value = $location->contentInfo->currentVersionNo;
                    break;
                case 'depth':
                    $value = $location->depth;
                    break;
                case 'is_hidden':
                    $value = $location->hidden;
                    break;
                case 'main_location_id':
                    $value = $location->contentInfo->mainLocationId;
                    break;
                case 'main_language_code':
                    $value = $location->contentInfo->mainLanguageCode;
                    break;
                case 'modification_date':
                    $value = $location->contentInfo->modificationDate->getTimestamp();
                    break;
                case 'name':
                    $value = $location->contentInfo->name;
                    break;
                case 'owner_id':
                    $value = $location->contentInfo->ownerId;
                    break;
                case 'parent_location_id':
                    $value = $location->parentLocationId;
                    break;
                case 'path':
                    $value = $location->pathString;
                    break;
                case 'position':
                    $value = $location->position;
                    break;
                case 'priority':
                    $value = $location->priority;
                    break;
                case 'publication_date':
                    $value = $location->contentInfo->publishedDate->getTimestamp();
                    break;
                case 'section_id':
                    $value = $location->contentInfo->sectionId;
                    break;
                case 'sort_field':
                    $value = $this->sortConverter->sortField2Hash($location->sortField);
                    break;
                case 'sort_order':
                    $value = $this->sortConverter->sortOrder2Hash($location->sortOrder);
                    break;
                default:
                    throw new \InvalidArgumentException('Location Manager does not support setting references for attribute ' . $reference['attribute']);
            }

            $this->referenceResolver->addReference($reference['identifier'], $value);
        }

        return true;
    }
}
