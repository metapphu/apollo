<?php

namespace Metapp\Apollo\Form\View\Helper;

use Metapp\Apollo\Form\View\Helper\ElementRender;
use Laminas\Form\Element;
use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\Form;

class FormEnd extends Form
{
    public function render(FormInterface $form): string
    {
        $content = "";
        $ajax = $form->getOption("ajax");
        if (!empty($ajax)) {
            $ajaxElement = new Element\Hidden('ajax_request');
            $ajaxElement->setAttributes(array(
                'data-method' => (!empty($ajax["method"]) ? $ajax["method"] : $form->getAttribute("method")),
                'data-type' => (!empty($ajax["datatype"]) ? $ajax["datatype"] : ""),
                'data-url' => (!empty($ajax["url"]) ? $ajax["url"] : $form->getAttribute("action")),
            ));
            $render = new ElementRender();
            $content .= $render->render($ajaxElement);
        }
        $content .= $this->closeTag();
        return $content;
    }
}
