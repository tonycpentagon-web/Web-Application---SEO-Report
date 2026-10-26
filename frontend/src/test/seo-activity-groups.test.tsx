import {expect,it} from 'vitest';
import {render,screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {SeoActivityGroups} from '../components/seo-activity-groups';
import type {DashboardRecord} from '../lib/report-dashboard';
function row(id:string,category:string,url?:string):DashboardRecord{return {id,kind:'seo_activities',section_key:'seo_work',data:{category,url},revision:1,review_status:'pending',human_verified:false,provisional:true,issues:[],sources:[],public_sources:[]};}
it('shows summary counts, collapsed category URL badges and expandable source-backed lists',async()=>{
 const {container}=render(<SeoActivityGroups activities={[row('1','profile_creation','https://one.example'),row('2','article_submission','https://two.example'),row('3','profile_creation','https://one.example')]}/>);
 expect([...container.querySelectorAll('.studio-kpi>strong')].map(e=>e.textContent)).toEqual(['3','1','2']);
 const groups=container.querySelectorAll('details.activity-group');expect(groups).toHaveLength(2);
 expect(groups[0]).not.toHaveAttribute('open');expect(groups[1].querySelector('.activity-url-count')).toHaveTextContent('2 URLs');
 await userEvent.click(screen.getByRole('heading',{name:'Profile Creation'}));expect(groups[1]).toHaveAttribute('open');
 expect(screen.getAllByRole('link',{name:'https://one.example'})).toHaveLength(2);
});
it('uses new monthly data and new categories on rerender without keeping prior counts or URLs',()=>{
 const {container,rerender}=render(<SeoActivityGroups activities={[row('1','guest_post','https://old.example')]}/>);
 rerender(<SeoActivityGroups activities={[row('2','press_release','https://new.example'),row('3','press_release','https://another.example')]}/>);
 expect(screen.queryByText('https://old.example')).not.toBeInTheDocument();expect(screen.queryByRole('heading',{name:'Guest Post'})).not.toBeInTheDocument();
 expect(screen.getByRole('heading',{name:'Press Release'})).toBeInTheDocument();expect([...container.querySelectorAll('.studio-kpi>strong')].map(e=>e.textContent)).toEqual(['2','2']);
});
it('preserves missing or unsafe URL observations without making unsafe links or inflating URL counts',async()=>{
 const {container}=render(<SeoActivityGroups activities={[row('1','image_submission','javascript:alert(1)'),row('2','image_submission')]}/>);
 await userEvent.click(screen.getByRole('heading',{name:'Image Submission'}));
 expect(screen.queryByRole('link')).not.toBeInTheDocument();expect(container.querySelector('.activity-url-count')).toHaveTextContent('0 URLs');
 expect(container.querySelectorAll('ol>li')).toHaveLength(2);expect(container.querySelector('.studio-kpi>strong')).toHaveTextContent('2');
});
