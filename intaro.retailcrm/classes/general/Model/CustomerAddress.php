<?php
/**
 * Class CustomerAddress
 */
class CustomerAddress extends BaseModel
{
    /**@var string $index */
    protected $index;

    /**@var string $country */
    protected $country;

    /**@var string $region */
    protected $region;

    /**@var string $city */
    protected $city;

    /**@var string $street */
    protected $street;

    /**@var string $building */
    protected $building;

    /**@var string $house */
    protected $house;

    /**@var string $block */
    protected $block;

    /**@var string $flat */
    protected $flat;

    /**@var string $floor */
    protected $floor;

    /**@var string $intercomCode */
    protected $intercomCode;

    /**@var string $metro */
    protected $metro;

    /**@var string $notes */
    protected $notes;

    /**@var string $text */
    protected $text;
    
    /**
     * @param string $index
     * @return $this
     */
    public function setIndex(string $index): CustomerAddress
    {
        $this->index = $index;

        return $this;
    }
    
    /**
     * @param string $country
     * @return $this
     */
    public function setCountry(string $country): CustomerAddress
    {
        $this->country = $country;

        return $this;
    }
    
    /**
     * @param string $region
     * @return $this
     */
    public function setRegion(string $region): CustomerAddress
    {
        $this->region = $region;

        return $this;
    }
    
    /**
     * @param string $city
     * @return $this
     */
    public function setCity(string $city): CustomerAddress
    {
        $this->city = $city;

        return $this;
    }
    
    /**
     * @param string $street
     * @return $this
     */
    public function setStreet(string $street): CustomerAddress
    {
        $this->street = $street;

        return $this;
    }
    
    /**
     * @param string $building
     * @return $this
     */
    public function setBuilding(string $building): CustomerAddress
    {
        $this->building = $building;

        return $this;
    }
    
    /**
     * @param string $house
     * @return $this
     */
    public function setHouse(string $house): CustomerAddress
    {
        $this->house = $house;

        return $this;
    }
    
    /**
     * @param string $block
     * @return $this
     */
    public function setBlock(string $block): CustomerAddress
    {
        $this->block = $block;

        return $this;
    }
    
    /**
     * @param string $flat
     * @return $this
     */
    public function setFlat(string $flat): CustomerAddress
    {
        $this->flat = $flat;

        return $this;
    }
    
    /**
     * @param string $floor
     * @return $this
     */
    public function setFloor(string $floor): CustomerAddress
    {
        $this->floor = $floor;

        return $this;
    }
    
    /**
     * @param string $intercomCode
     * @return $this
     */
    public function setIntercomCode(string $intercomCode): CustomerAddress
    {
        $this->intercomCode = $intercomCode;

        return $this;
    }
    
    /**
     * @param string $metro
     * @return $this
     */
    public function setMetro(string $metro): CustomerAddress
    {
        $this->metro = $metro;

        return $this;
    }
    
    /**
     * @param string $notes
     * @return $this
     */
    public function setNotes(string $notes): CustomerAddress
    {
        $this->notes = $notes;

        return $this;
    }
    
    /**
     * @param string $text
     * @return $this
     */
    public function setText(string $text): CustomerAddress
    {
        $this->text = $text;

        return $this;
    }
}
